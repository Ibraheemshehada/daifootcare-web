<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\EngagementEvent;
use App\Models\SatisfactionEntry;
use App\Models\SusResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates for the usability study.
 *
 * This is the view the research side needs and the clinical pages do not: SUS
 * distribution, satisfaction, engagement, and which consent version each
 * participant is on.
 */
class StudyController extends Controller
{
    public function summary(): JsonResponse
    {
        $scores = SusResponse::pluck('score')->filter()->values();

        // Median as well as mean: SUS on a small cohort is easily skewed by one
        // outlier, and reporting only the mean would hide that.
        $sorted = $scores->sort()->values();
        $count = $sorted->count();
        $median = $count === 0 ? null : ($count % 2
            ? $sorted[intdiv($count, 2)]
            : ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2);

        $bands = ['excellent' => 0, 'good' => 0, 'ok' => 0, 'poor' => 0];
        foreach ($scores as $s) {
            $bands[match (true) {
                $s >= 85 => 'excellent',
                $s >= 68 => 'good',
                $s >= 51 => 'ok',
                default => 'poor',
            }]++;
        }

        return response()->json([
            'sus' => [
                'responses' => $count,
                'mean' => $count ? round($scores->avg(), 1) : null,
                'median' => $median !== null ? round($median, 1) : null,
                // 68 is the conventional SUS average, not a pass mark.
                'benchmark' => 68,
                'bands' => $bands,
            ],
            'satisfaction' => [
                'responses' => SatisfactionEntry::count(),
                'ease_of_use' => round(SatisfactionEntry::avg('ease_of_use') ?? 0, 2) ?: null,
                'usefulness' => round(SatisfactionEntry::avg('usefulness') ?? 0, 2) ?: null,
                'would_continue' => round(SatisfactionEntry::avg('would_continue') ?? 0, 2) ?: null,
            ],
            'engagement' => [
                'events_total' => EngagementEvent::count(),
                'app_opens' => EngagementEvent::where('name', 'app_open')->count(),
                'active_participants_7d' => EngagementEvent::where('occurred_at', '>=', now()->subDays(7))
                    ->distinct('patient_id')->count('patient_id'),
                'top_features' => EngagementEvent::where('name', 'feature_open')
                    ->select('target', DB::raw('COUNT(*) as opens'))
                    ->whereNotNull('target')
                    ->groupBy('target')
                    ->orderByDesc('opens')
                    ->limit(8)
                    ->get(),
            ],
            // Which declaration participants are on. A cohort split across two
            // consent versions is a fact the study has to be able to see.
            'consent' => [
                'by_version' => ConsentRecord::select('version', DB::raw('COUNT(DISTINCT patient_id) as participants'))
                    ->groupBy('version')
                    ->orderByDesc('version')
                    ->get(),
            ],
        ]);
    }
}
