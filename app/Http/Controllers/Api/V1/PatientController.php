<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MedicationLog;
use App\Models\Patient;
use App\Models\SelfCareLog;
use App\Models\WoundScan;
use Illuminate\Http\JsonResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    /** Days without a scan before a participant is treated as having gone quiet. */
    private const QUIET_DAYS = 14;

    /** Days without a device check-in before its records are assumed stranded. */
    private const DEVICE_SILENT_DAYS = 7;

    /** The self-care checklist is five fixed items. */
    private const SELF_CARE_ITEMS = 5;

    /**
     * Grace period after enrolment before absence counts as a concern.
     *
     * Somebody enrolled yesterday who has not scanned yet has done nothing
     * wrong. Flagging them on day one floods the board with cards nobody can act
     * on, and a queue that cries wolf stops being read — which costs more than
     * the flag was ever worth.
     */
    private const ONBOARDING_GRACE_DAYS = 3;

    /**
     * The monitoring list.
     *
     * Returns enough per patient to decide at a glance who needs attention:
     * risk, recency, adherence, device health, and a short wound-area series for
     * a sparkline.
     *
     * Each metric is one grouped query keyed by patient_id, not a lookup per
     * row. Done per row this endpoint would fire six queries per patient and
     * become the slowest page in the system exactly as the cohort grows.
     */
    public function index(Request $request): JsonResponse
    {
        $since = now()->subDays(30)->toDateString();

        $patients = Patient::query()
            ->with('user:id,name,email,role,is_guest,guest_device_uuid,claimed_at')
            ->withCount('woundScans')
            // Newest scan first, so the list mirrors clinical attention.
            ->withMax('woundScans as last_scan_at', 'captured_at')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->orderByDesc('last_scan_at')
            ->paginate($request->integer('per_page', 24));

        $collection = $patients->getCollection();

        if ($collection->isEmpty()) {
            return response()->json($patients);
        }

        $ids = $collection->pluck('id')->all();
        $userIds = $collection->pluck('user_id')->all();

        $scansByPatient = WoundScan::whereIn('patient_id', $ids)
            ->orderByDesc('captured_at')
            ->get(['patient_id', 'captured_at', 'risk_badge', 'infection_present',
                'ischaemia_present', 'area_cm2'])
            ->groupBy('patient_id');

        $selfCare = SelfCareLog::whereIn('patient_id', $ids)
            ->where('log_date', '>=', $since)
            ->selectRaw('patient_id, COUNT(*) as done, COUNT(DISTINCT log_date) as days')
            ->groupBy('patient_id')->get()->keyBy('patient_id');

        $meds = MedicationLog::whereIn('patient_id', $ids)
            ->where('log_date', '>=', $since)
            ->selectRaw('patient_id, COUNT(*) as logged, SUM(CASE WHEN taken = 1 THEN 1 ELSE 0 END) as taken')
            ->groupBy('patient_id')->get()->keyBy('patient_id');

        $devices = Device::whereIn('user_id', $userIds)
            ->selectRaw('user_id, COUNT(*) as total, MAX(last_seen_at) as last_seen')
            ->groupBy('user_id')->get()->keyBy('user_id');

        $collection->transform(function ($p) use ($scansByPatient, $selfCare, $meds, $devices) {
            $scans = $scansByPatient->get($p->id) ?? collect();
            $latest = $scans->first();

            $p->latest_risk = $latest?->risk_badge;
            $p->latest_infection = (bool) $latest?->infection_present;
            $p->latest_ischaemia = (bool) $latest?->ischaemia_present;

            // Oldest→newest for the sparkline. Zero-area scans are dropped: a
            // 0×0 result means the model found no wound in the photo, not that
            // the wound closed, and plotting it would read as healing.
            $p->area_series = $scans->reverse()->values()
                ->filter(fn ($s) => (float) $s->area_cm2 > 0)
                ->map(fn ($s) => round((float) $s->area_cm2, 2))
                ->values()->take(-12)->all();

            $sc = $selfCare->get($p->id);
            $p->self_care_adherence = ($sc && $sc->days > 0)
                ? (int) round($sc->done / ($sc->days * self::SELF_CARE_ITEMS) * 100)
                : null;

            $md = $meds->get($p->id);
            $p->medication_adherence = ($md && $md->logged > 0)
                ? (int) round($md->taken / $md->logged * 100)
                : null;

            $dev = $devices->get($p->user_id);
            $p->devices_count = (int) ($dev->total ?? 0);
            // withMax/selectRaw hand back a raw string, not a Carbon instance,
            // so it is parsed here rather than assumed to be a date object.
            $p->device_last_seen = $dev?->last_seen ? Carbon::parse($dev->last_seen) : null;

            $p->attention = $this->attentionFor($p);

            return $p;
        });

        return response()->json($patients);
    }

    /**
     * Why this patient might need looking at.
     *
     * A list of reasons rather than a single score. A score collapses "their
     * wound is infected" and "they have not opened the app" into one number, and
     * those call for completely different actions by different people.
     */
    private function attentionFor(Patient $p): array
    {
        $reasons = [];

        if ($p->latest_infection && $p->latest_ischaemia) {
            $reasons[] = ['level' => 'critical', 'key' => 'high_risk'];
        } elseif ($p->latest_infection) {
            $reasons[] = ['level' => 'critical', 'key' => 'infection'];
        } elseif ($p->latest_ischaemia) {
            $reasons[] = ['level' => 'warning', 'key' => 'ischaemia'];
        }

        // Absence only becomes a finding once the participant has had a fair
        // chance to start. Before that it is just a new enrolment.
        //
        // Compared with an explicit cutoff rather than diffInDays(): Carbon 3
        // returns a *signed* difference, so `now()->diffInDays($past)` is
        // negative and every `> N` check here silently evaluated to false. These
        // flags would never have fired, and nothing would have looked broken.
        $settledIn = $p->created_at === null
            || $p->created_at->lte(now()->subDays(self::ONBOARDING_GRACE_DAYS));

        if ($p->wound_scans_count === 0) {
            if ($settledIn) {
                $reasons[] = ['level' => 'info', 'key' => 'never_scanned'];
            }
        } elseif ($p->last_scan_at
            && Carbon::parse($p->last_scan_at)->lt(now()->subDays(self::QUIET_DAYS))) {
            $reasons[] = ['level' => 'warning', 'key' => 'quiet'];
        }

        if ($p->devices_count === 0) {
            if ($settledIn) {
                $reasons[] = ['level' => 'info', 'key' => 'no_device'];
            }
        } elseif ($p->device_last_seen
            && $p->device_last_seen->lt(now()->subDays(self::DEVICE_SILENT_DAYS))) {
            // A device that stopped reporting means records may be stranded on
            // the phone: the chart is stale, not the patient improving.
            $reasons[] = ['level' => 'warning', 'key' => 'device_silent'];
        }

        if ($p->medication_adherence !== null && $p->medication_adherence < 50) {
            $reasons[] = ['level' => 'warning', 'key' => 'medication_low'];
        }

        return $reasons;
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        $patient->load(['user:id,name,email,role']);
        $patient->loadCount('woundScans');

        return response()->json(['patient' => $patient]);
    }
}
