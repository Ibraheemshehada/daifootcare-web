<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * Register (or re-register) an install. Idempotent on device_uuid so a
     * reinstall or an app relaunch does not create duplicate device rows.
     */
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'mode' => ['required', 'string', 'in:online,offline'],
        ]);

        $existing = Device::where('device_uuid', $data['device_uuid'])->first();

        // A device_uuid already claimed by someone else must not be silently
        // reassigned — that would attach one person's scans to another's account.
        if ($existing && $existing->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'This device_uuid is already registered to another account.',
            ], 409);
        }

        $device = Device::updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'],
                'app_version' => $data['app_version'] ?? null,
                'mode' => $data['mode'],
                'last_seen_at' => now(),
            ]
        );

        return response()->json(['device' => $device], $device->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Update the mode, or confirm that the offline model bundle finished downloading.
     */
    public function updateMode(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['nullable', 'string', 'in:online,offline'],
            'models_downloaded' => ['nullable', 'boolean'],
            'models_version' => ['nullable', 'string', 'max:50'],
            'app_version' => ['nullable', 'string', 'max:50'],
        ]);

        $device = Device::where('device_uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (isset($data['mode'])) {
            $device->mode = $data['mode'];
        }

        if (array_key_exists('models_downloaded', $data)) {
            $device->models_downloaded_at = $data['models_downloaded'] ? now() : null;
        }

        if (isset($data['models_version'])) {
            $device->models_version = $data['models_version'];
        }

        if (isset($data['app_version'])) {
            $device->app_version = $data['app_version'];
        }

        $device->last_seen_at = now();
        $device->save();

        return response()->json(['device' => $device]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $devices = Device::query()
            ->with('user:id,name,email,role')
            ->when(! $user->isClinician(), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('last_seen_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($devices);
    }
}
