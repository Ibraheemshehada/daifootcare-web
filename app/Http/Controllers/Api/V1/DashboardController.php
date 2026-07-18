<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Patient;
use App\Models\User;
use App\Models\SyncLog;
use App\Models\WoundScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
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
