<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GlucoseReading;
use App\Models\SelfCareLog;
use App\Models\SusResponse;
use App\Models\WoundScan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for analysis.
 *
 * Streamed rather than built in memory: a study export grows without bound and
 * a dashboard that dies on the largest dataset is useless exactly when it
 * matters.
 */
class ExportController extends Controller
{
    private const TYPES = ['wound-scans', 'glucose', 'sus', 'self-care'];

    public function download(Request $request, string $type): StreamedResponse
    {
        abort_unless(in_array($type, self::TYPES, true), 404, 'Unknown export type.');

        // Exports leave the system, so record who took what.
        Log::info('study export', [
            'type' => $type,
            'user_id' => $request->user()->id,
            'ip' => $request->ip(),
        ]);

        [$headers, $rows] = $this->dataFor($type);

        $filename = "diafootcare-{$type}-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens Arabic patient names as UTF-8 rather than mojibake.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function dataFor(string $type): array
    {
        return match ($type) {
            'wound-scans' => [
                ['local_uuid', 'patient_id', 'patient', 'captured_at', 'length_cm', 'width_cm',
                    'area_cm2', 'depth_cm', 'infection', 'ischaemia', 'risk_badge', 'source', 'models_version'],
                WoundScan::with('patient.user:id,name')->orderBy('captured_at')->lazy()
                    ->map(fn ($s) => [
                        $s->local_uuid, $s->patient_id, $s->patient?->user?->name,
                        $s->captured_at, $s->length_cm, $s->width_cm, $s->area_cm2, $s->depth_cm,
                        $this->bool($s->infection_present), $this->bool($s->ischaemia_present),
                        $s->risk_badge, $s->source, $s->models_version,
                    ]),
            ],
            'glucose' => [
                ['local_uuid', 'patient_id', 'value_mgdl', 'tag', 'measured_at'],
                GlucoseReading::orderBy('measured_at')->lazy()
                    ->map(fn ($g) => [$g->local_uuid, $g->patient_id, $g->value_mgdl, $g->tag, $g->measured_at]),
            ],
            // Raw items, not just the composite: the whole point of storing q1..q10
            // is that the study can re-analyse per item.
            'sus' => [
                ['local_uuid', 'patient_id', 'q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9', 'q10',
                    'score', 'consent_version', 'recorded_at'],
                SusResponse::orderBy('recorded_at')->lazy()
                    ->map(fn ($s) => [
                        $s->local_uuid, $s->patient_id,
                        $s->q1, $s->q2, $s->q3, $s->q4, $s->q5, $s->q6, $s->q7, $s->q8, $s->q9, $s->q10,
                        $s->score, $s->consent_version, $s->recorded_at,
                    ]),
            ],
            'self-care' => [
                ['local_uuid', 'patient_id', 'item_key', 'log_date', 'done_at'],
                SelfCareLog::orderBy('log_date')->lazy()
                    ->map(fn ($l) => [$l->local_uuid, $l->patient_id, $l->item_key, $l->log_date, $l->done_at]),
            ],
        };
    }

    /** Null must stay distinct from false: "not assessed" is not "absent". */
    private function bool(?bool $v): string
    {
        return $v === null ? '' : ($v ? '1' : '0');
    }
}
