<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\WoundScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Cross-patient clinical views: the lists a clinician works from rather than
 * one patient's chart.
 */
class ClinicalController extends Controller
{
    /**
     * Scans needing attention, newest first.
     *
     * This is the one screen that exists to be acted on, so it is ordered by
     * severity first and recency second — a week-old high-risk scan still
     * outranks today's normal one.
     */
    public function alerts(Request $request): JsonResponse
    {
        $scans = WoundScan::query()
            ->with(['patient.user:id,name,email,is_guest'])
            ->where(fn ($q) => $q->where('infection_present', true)->orWhere('ischaemia_present', true))
            ->when($request->filled('days'), fn ($q) => $q->where('captured_at', '>=', now()->subDays($request->integer('days'))))
            ->orderByRaw('(CASE WHEN infection_present = 1 AND ischaemia_present = 1 THEN 0
                                WHEN infection_present = 1 THEN 1
                                ELSE 2 END)')
            ->orderByDesc('captured_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($scans);
    }

    public function appointments(Request $request): JsonResponse
    {
        $scope = $request->string('scope')->toString() ?: 'upcoming';

        $appointments = Appointment::query()
            ->with('patient.user:id,name,email')
            ->when($scope === 'upcoming', fn ($q) => $q->where('scheduled_at', '>=', now())->orderBy('scheduled_at'))
            ->when($scope === 'past', fn ($q) => $q->where('scheduled_at', '<', now())->orderByDesc('scheduled_at'))
            ->when($scope === 'all', fn ($q) => $q->orderByDesc('scheduled_at'))
            ->paginate($request->integer('per_page', 25));

        return response()->json($appointments);
    }

    /**
     * Medications with a 30-day adherence figure per patient.
     *
     * Adherence is taken/logged rather than taken/scheduled: the app only writes
     * a log when a dose is interacted with, so a scheduled-dose denominator
     * would silently count "never opened the app" as "missed every dose".
     */
    public function medications(Request $request): JsonResponse
    {
        $since = now()->subDays(30)->toDateString();

        $meds = Medication::query()
            ->with('patient.user:id,name,email')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        $adherence = MedicationLog::query()
            ->where('log_date', '>=', $since)
            ->select('patient_id',
                DB::raw('COUNT(*) as logged'),
                DB::raw('SUM(CASE WHEN taken = 1 THEN 1 ELSE 0 END) as taken'))
            ->groupBy('patient_id')
            ->get()
            ->keyBy('patient_id');

        $meds->getCollection()->transform(function ($m) use ($adherence) {
            $row = $adherence->get($m->patient_id);
            $m->adherence_30d = $row && $row->logged
                ? round($row->taken / $row->logged * 100)
                : null;

            return $m;
        });

        return response()->json($meds);
    }
}
