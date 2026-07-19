<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\EngagementDaily;
use App\Models\Patient;
use App\Models\User;
use App\Models\SyncLog;
use App\Models\WoundScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Daily series for the dashboard charts.
     *
     * Every day in the window is emitted, including the empty ones. A chart that
     * silently drops days with no activity compresses the gaps and makes a
     * fortnight of silence look like continuous use — the opposite of what a
     * study monitoring engagement needs to see.
     */
    public function trends(Request $request): JsonResponse
    {
        $days = max(7, min(90, $request->integer('days', 30)));
        $from = now()->subDays($days - 1)->startOfDay();

        $scansByDay = WoundScan::where('captured_at', '>=', $from)
            ->selectRaw('DATE(captured_at) as day, COUNT(*) as c')
            ->groupBy('day')->pluck('c', 'day');

        $syncByDay = SyncLog::where('created_at', '>=', $from)
            ->selectRaw("DATE(created_at) as day,
                         SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as ok,
                         SUM(CASE WHEN status IN ('failed','partial') THEN 1 ELSE 0 END) as bad")
            ->groupBy('day')->get()->keyBy('day');

        $activeByDay = EngagementDaily::where('day', '>=', $from->toDateString())
            ->selectRaw('day, COUNT(DISTINCT patient_id) as c')
            ->groupBy('day')->pluck('c', 'day');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $series[] = [
                'day' => $date,
                'scans' => (int) ($scansByDay[$date] ?? 0),
                'sync_ok' => (int) ($syncByDay[$date]->ok ?? 0),
                'sync_failed' => (int) ($syncByDay[$date]->bad ?? 0),
                'active_participants' => (int) ($activeByDay[$date] ?? 0),
            ];
        }

        return response()->json([
            'days' => $days,
            'series' => $series,
            // Ordered by severity, so the chart reads as a ranking rather than an
            // arbitrary key.
            'risk_distribution' => [
                'high' => WoundScan::where('infection_present', true)->where('ischaemia_present', true)->count(),
                'infection' => WoundScan::where('infection_present', true)->where('ischaemia_present', false)->count(),
                'ischaemia' => WoundScan::where('ischaemia_present', true)->where('infection_present', false)->count(),
                'normal' => WoundScan::where('infection_present', false)->where('ischaemia_present', false)->count(),
            ],
        ]);
    }

    public function stats(Request $request): JsonResponse
    {
        return response()->json([
            'patients' => Patient::count(),
            'participants' => [
                // Guests are participants too; the study needs to see the split
                // rather than have anonymous activity silently folded in.
                'guests' => User::where('is_guest', true)->count(),
                'registered' => User::where('is_guest', false)
                    ->where('role', User::ROLE_PATIENT)->count(),
                'claimed' => User::whereNotNull('claimed_at')->count(),
            ],
            'devices' => [
                'total' => Device::count(),
                'online_mode' => Device::where('mode', Device::MODE_ONLINE)->count(),
                'offline_mode' => Device::where('mode', Device::MODE_OFFLINE)->count(),
                'models_downloaded' => Device::whereNotNull('models_downloaded_at')->count(),
                'active_7d' => Device::where('last_seen_at', '>=', now()->subDays(7))->count(),
            ],
            'scans' => [
                'total' => WoundScan::count(),
                'last_7d' => WoundScan::where('captured_at', '>=', now()->subDays(7))->count(),
                'last_30d' => WoundScan::where('captured_at', '>=', now()->subDays(30))->count(),
                'infection_flagged' => WoundScan::where('infection_present', true)->count(),
                'ischaemia_flagged' => WoundScan::where('ischaemia_present', true)->count(),
            ],
            'sync' => [
                'batches_24h' => SyncLog::where('created_at', '>=', now()->subDay())->count(),
                'failed_24h' => SyncLog::where('created_at', '>=', now()->subDay())
                    ->whereIn('status', ['failed', 'partial'])->count(),
            ],
        ]);
    }
}
