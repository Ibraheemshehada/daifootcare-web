<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ConsentRecord;
use App\Models\GlucoseReading;
use App\Models\MedicationLog;
use App\Models\Patient;
use App\Models\QolEntry;
use App\Models\SatisfactionEntry;
use App\Models\SelfCareLog;
use App\Models\SusResponse;
use App\Models\WoundScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The whole record for one patient, assembled for the clinician detail view.
 *
 * Returned as one payload rather than eight endpoints because the page shows it
 * as one chart — eight round trips would mean eight skeleton states resolving at
 * different times, which reads as broken.
 */
class PatientRecordController extends Controller
{
    /** Self-care checklist is a fixed five items; adherence is measured against it. */
    private const SELF_CARE_ITEMS = 5;

    public function show(Request $request, Patient $patient): JsonResponse
    {
        $patient->load('user:id,name,email,role,locale');

        $since = now()->subDays(30);

        $scans = WoundScan::where('patient_id', $patient->id)
            ->orderByDesc('captured_at')->limit(50)->get();

        $glucose = GlucoseReading::where('patient_id', $patient->id)
            ->orderByDesc('measured_at')->limit(60)->get();

        $qol = QolEntry::where('patient_id', $patient->id)
            ->orderByDesc('recorded_at')->limit(30)->get();

        $sus = SusResponse::where('patient_id', $patient->id)
            ->orderByDesc('recorded_at')->get();

        $appointments = Appointment::where('patient_id', $patient->id)
            ->orderBy('scheduled_at')->get();

        // Self-care adherence over the last 30 days: completed items divided by
        // the number that could have been completed on days the patient engaged.
        $selfCareDays = SelfCareLog::where('patient_id', $patient->id)
            ->where('log_date', '>=', $since->toDateString())
            ->selectRaw('log_date, COUNT(*) as done')
            ->groupBy('log_date')
            ->orderBy('log_date')
            ->get();

        $selfCareAdherence = $selfCareDays->count() === 0 ? null : round(
            $selfCareDays->sum('done') / ($selfCareDays->count() * self::SELF_CARE_ITEMS) * 100
        );

        $medLogs = MedicationLog::where('patient_id', $patient->id)
            ->where('log_date', '>=', $since->toDateString())
            ->get();

        $medicationAdherence = $medLogs->count() === 0
            ? null
            : round($medLogs->where('taken', true)->count() / $medLogs->count() * 100);

        return response()->json([
            'patient' => $patient,
            'summary' => [
                'scans_total' => $scans->count(),
                'latest_scan_at' => $scans->first()?->captured_at,
                'latest_risk' => $scans->first()?->risk_badge,
                'glucose_avg_7d' => round(
                    GlucoseReading::where('patient_id', $patient->id)
                        ->where('measured_at', '>=', now()->subDays(7))
                        ->avg('value_mgdl') ?? 0,
                    1
                ) ?: null,
                'self_care_adherence_30d' => $selfCareAdherence,
                'medication_adherence_30d' => $medicationAdherence,
                'sus_latest' => $sus->first()?->score,
                'sus_band' => $sus->first()?->band(),
            ],
            'wound_scans' => $scans,
            'glucose' => $glucose,
            'qol' => $qol,
            'satisfaction' => SatisfactionEntry::where('patient_id', $patient->id)
                ->orderByDesc('recorded_at')->limit(10)->get(),
            'sus' => $sus,
            'appointments' => $appointments,
            'self_care_days' => $selfCareDays,
            // Consent history is part of the clinical record: it is the evidence
            // of what this participant agreed to and when.
            'consents' => ConsentRecord::where('patient_id', $patient->id)
                ->orderByDesc('version')->get(),
        ]);
    }
}
