<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\SyncLog;
use App\Models\WoundScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class WoundScanSyncController extends Controller
{
    /** Cap per request so a device with a long offline backlog can't time the request out. */
    private const MAX_BATCH = 50;

    /**
     * Receive a batch of locally-captured scans from a device.
     *
     * Idempotent: each record carries a `local_uuid` generated on the device at
     * capture time, and is upserted on it. A batch that is re-sent because the
     * response was lost mid-flight therefore updates rather than duplicates.
     *
     * Partial success is normal and expected — a record that fails validation must
     * not discard the rest of the batch, because the device will keep retrying the
     * whole pending set until each row is individually acknowledged.
     */
    public function sync(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'batch_uuid' => ['nullable', 'uuid'],
            'records' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'records.*.local_uuid' => ['required', 'uuid'],
            'records.*.captured_at' => ['required', 'date'],
            'records.*.length_cm' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'records.*.width_cm' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'records.*.area_cm2' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'records.*.depth_cm' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'records.*.is_calibrated' => ['nullable', 'boolean'],
            'records.*.tissue_json' => ['nullable', 'array'],
            'records.*.infection_present' => ['nullable', 'boolean'],
            'records.*.infection_prob' => ['nullable', 'numeric', 'between:0,1'],
            'records.*.ischaemia_present' => ['nullable', 'boolean'],
            'records.*.ischaemia_prob' => ['nullable', 'numeric', 'between:0,1'],
            'records.*.risk_badge' => ['nullable', 'string', 'max:50'],
            'records.*.models_version' => ['nullable', 'string', 'max:50'],
            'records.*.source' => ['nullable', Rule::in(['offline', 'online'])],
        ]);

        $user = $request->user();

        $device = Device::where('device_uuid', $data['device_uuid'])
            ->where('user_id', $user->id)
            ->first();

        if (! $device) {
            return response()->json([
                'message' => 'Unknown device for this account. Register the device first.',
            ], 404);
        }

        // The owning patient is derived from the authenticated user, never taken from
        // the request body — otherwise a device could file scans against someone else's
        // chart just by sending a different patient_id.
        $patient = $user->patient()->first();

        if (! $patient) {
            return response()->json([
                'message' => 'This account has no patient record to attach scans to.',
            ], 422);
        }

        $synced = [];
        $failed = [];

        foreach ($data['records'] as $record) {
            try {
                DB::transaction(function () use ($record, $patient, $device) {
                    WoundScan::updateOrCreate(
                        ['local_uuid' => $record['local_uuid']],
                        [
                            'patient_id' => $patient->id,
                            'device_id' => $device->id,
                            'captured_at' => $record['captured_at'],
                            'length_cm' => $record['length_cm'] ?? null,
                            'width_cm' => $record['width_cm'] ?? null,
                            'area_cm2' => $record['area_cm2'] ?? null,
                            'depth_cm' => $record['depth_cm'] ?? null,
                            'is_calibrated' => $record['is_calibrated'] ?? false,
                            'tissue_json' => $record['tissue_json'] ?? null,
                            'infection_present' => $record['infection_present'] ?? null,
                            'infection_prob' => $record['infection_prob'] ?? null,
                            'ischaemia_present' => $record['ischaemia_present'] ?? null,
                            'ischaemia_prob' => $record['ischaemia_prob'] ?? null,
                            'risk_badge' => $record['risk_badge'] ?? null,
                            'models_version' => $record['models_version'] ?? null,
                            'source' => $record['source'] ?? 'offline',
                            'synced_at' => now(),
                        ]
                    );
                });

                $synced[] = $record['local_uuid'];
            } catch (Throwable $e) {
                // Log the detail server-side; return only a generic reason to the client.
                Log::error('wound-scan sync failed for a record', [
                    'local_uuid' => $record['local_uuid'],
                    'device_id' => $device->id,
                    'exception' => $e->getMessage(),
                ]);

                $failed[] = [
                    'local_uuid' => $record['local_uuid'],
                    'reason' => 'Could not be stored; it will be retried.',
                ];
            }
        }

        $device->touchLastSeen();

        SyncLog::create([
            'device_id' => $device->id,
            'batch_uuid' => $data['batch_uuid'] ?? null,
            'records_count' => count($data['records']),
            'synced_count' => count($synced),
            'failed_count' => count($failed),
            'status' => match (true) {
                $failed === [] => 'success',
                $synced === [] => 'failed',
                default => 'partial',
            },
        ]);

        return response()->json([
            'synced' => $synced,
            'failed' => $failed,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $scans = WoundScan::query()
            ->with(['patient.user:id,name,email', 'device:id,device_uuid,platform,mode'])
            ->when($request->filled('patient_id'), fn ($q) => $q->where('patient_id', $request->integer('patient_id')))
            // A patient may only ever read their own chart.
            ->when(! $user->isClinician(), function ($q) use ($user) {
                $q->whereHas('patient', fn ($p) => $p->where('user_id', $user->id));
            })
            ->orderByDesc('captured_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($scans);
    }
}
