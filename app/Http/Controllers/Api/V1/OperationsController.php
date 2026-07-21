<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Patient;
use App\Models\SyncLog;
use App\Models\User;
use App\Models\WoundScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Fleet health, sync monitoring and user administration. */
class OperationsController extends Controller
{
    public function device(Request $request, string $uuid): JsonResponse
    {
        $device = Device::with('user:id,name,email,role,is_guest')
            ->where('device_uuid', $uuid)
            ->firstOrFail();

        return response()->json([
            'device' => $device,
            'sync_logs' => SyncLog::where('device_id', $device->id)
                ->orderByDesc('created_at')->limit(25)->get(),
            'scans' => WoundScan::where('device_id', $device->id)
                ->orderByDesc('captured_at')->limit(20)->get(),
            'totals' => [
                'scans' => WoundScan::where('device_id', $device->id)->count(),
                'batches' => SyncLog::where('device_id', $device->id)->count(),
                'failed_batches' => SyncLog::where('device_id', $device->id)
                    ->whereIn('status', ['failed', 'partial'])->count(),
            ],
        ]);
    }

    /**
     * Sync health.
     *
     * Surfaces failures rather than throughput: a batch that never lands means a
     * patient's records are stuck on their phone, which is the failure mode that
     * actually matters here.
     */
    public function syncMonitor(Request $request): JsonResponse
    {
        $logs = SyncLog::with('device:id,device_uuid,platform,user_id')
            ->when($request->boolean('failed_only'),
                fn ($q) => $q->whereIn('status', ['failed', 'partial']))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'logs' => $logs,
            'summary' => [
                'batches_24h' => SyncLog::where('created_at', '>=', now()->subDay())->count(),
                'failed_24h' => SyncLog::where('created_at', '>=', now()->subDay())
                    ->whereIn('status', ['failed', 'partial'])->count(),
                'records_24h' => (int) SyncLog::where('created_at', '>=', now()->subDay())->sum('synced_count'),
                'by_status' => SyncLog::select('status', DB::raw('COUNT(*) as count'))
                    ->groupBy('status')->get(),
                // A device that registered but has not been seen for a week is
                // either uninstalled or silently failing to reach the API.
                'stale_devices' => Device::where(function ($q) {
                    $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(7));
                })->count(),
            ],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->withCount('devices')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->orderBy('role')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return response()->json($users);
    }

    /**
     * Create a user. Admin-only — the same privilege as granting a role.
     *
     * Exists so a study can add a second admin or a clinician without anyone
     * opening a database console, which is how ad-hoc SQL ends up being run
     * against live patient data.
     */
    public function createUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            // Long rather than ornate: a length floor resists guessing better
            // than a symbol requirement, which mostly produces "Password1!".
            'password' => ['required', 'string', 'min:12', 'max:200'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_DOCTOR, User::ROLE_PATIENT])],
            'locale' => ['nullable', 'string', 'in:en,ar'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'en',
            ]);

            // role is deliberately not mass-assignable, so a registration
            // request cannot promote itself. Set it explicitly.
            $user->forceFill(['role' => $data['role']])->save();

            // A patient with no Patient row cannot sync anything: the sync
            // endpoints resolve the owning patient from the user and return 422
            // when there is none. Creating it here means the account works when
            // it is handed over, instead of failing on the first scan.
            if ($data['role'] === User::ROLE_PATIENT) {
                Patient::firstOrCreate(['user_id' => $user->id]);
            }

            return $user;
        });

        return response()->json([
            'user' => $user->only(['id', 'name', 'email', 'role', 'locale']),
        ], 201);
    }

    /**
     * Change a user's role. Admin-only, and guarded in three ways.
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_DOCTOR, User::ROLE_PATIENT])],
        ]);

        // 1. You cannot change your own role — that is how an admin accidentally
        //    demotes themselves and locks everyone out.
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        // 2. A guest has no identified person behind it; promoting one to
        //    clinician would grant access to every patient record to whoever
        //    happens to hold that device.
        if ($user->is_guest && $data['role'] !== User::ROLE_PATIENT) {
            return response()->json([
                'message' => 'An anonymous guest cannot be given a clinical role.',
            ], 422);
        }

        // 3. Never remove the last admin.
        if ($user->role === User::ROLE_ADMIN && $data['role'] !== User::ROLE_ADMIN
            && User::where('role', User::ROLE_ADMIN)->count() <= 1) {
            return response()->json(['message' => 'There must be at least one admin.'], 422);
        }

        $user->forceFill(['role' => $data['role']])->save();

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'role'])]);
    }
}
