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
use Illuminate\Support\Facades\Storage;
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
            // { label, findings[] }. The findings are validated in shape
            // because the dashboard derives a clinical headline from them; a
            // malformed probability would otherwise become a wound described
            // wrongly rather than an obvious error here.
            'records.*.tissue_json' => ['nullable', 'array'],
            'records.*.tissue_json.label' => ['nullable', 'string', 'max:50'],
            'records.*.tissue_json.findings' => ['nullable', 'array', 'max:20'],
            'records.*.tissue_json.findings.*.type' => ['required_with:records.*.tissue_json.findings', 'string', 'max:50'],
            'records.*.tissue_json.findings.*.probability' => ['required_with:records.*.tissue_json.findings', 'numeric', 'between:0,1'],
            'records.*.tissue_json.findings.*.is_present' => ['required_with:records.*.tissue_json.findings', 'boolean'],
            'records.*.tissue_json.findings.*.threshold_used' => ['required_with:records.*.tissue_json.findings', 'numeric', 'between:0,1'],
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

    /**
     * Receive the photograph for a scan the device has already synced.
     *
     * Separate from sync() on purpose. sync() upserts batches of small JSON
     * rows; a photograph is megabytes of multipart that has to succeed or fail
     * on its own, and putting one inside a fifty-row batch would let a single
     * large upload fail forty-nine unrelated records.
     *
     * Keyed by local_uuid — the same identifier the record sync uses — and
     * scoped to the authenticated patient, so a device can only ever attach an
     * image to its own scan. A scan that has not synced yet returns 404, which
     * the client treats as "retry later" rather than as a permanent failure.
     *
     * Images are written to the PRIVATE disk, never public storage: a wound
     * photograph linked to an identified patient must not be reachable by
     * guessing a URL. Reading one goes through image() below, which checks the
     * caller first.
     */
    public function storeImage(Request $request, string $localUuid): JsonResponse
    {
        $request->validate([
            // 12 MB covers a full-resolution phone photograph. Explicit mime
            // list rather than "image": the analysis pipeline reads these, and
            // accepting anything decodable invites files it cannot use.
            'image' => ['required', 'file', 'max:12288', 'mimes:jpg,jpeg,png,heic,heif'],
        ]);

        $user = $request->user();
        $patient = $user?->patient()->first();

        if (! $patient) {
            return response()->json([
                'message' => 'This account has no patient record to attach images to.',
            ], 422);
        }

        $scan = WoundScan::where('local_uuid', $localUuid)
            ->where('patient_id', $patient->id)
            ->first();

        if (! $scan) {
            // The record has not arrived yet, or belongs to someone else. Both
            // are 404 deliberately: telling an unauthorised caller that a scan
            // exists but is not theirs leaks that it exists.
            return response()->json([
                'message' => 'No scan with that identifier for this patient.',
            ], 404);
        }

        try {
            // Replacing an image (a device retrying after a partial failure)
            // must not leave the previous file orphaned on disk.
            if ($scan->image_path && Storage::disk('local')->exists($scan->image_path)) {
                Storage::disk('local')->delete($scan->image_path);
            }

            $path = $request->file('image')->store(
                "wound-scans/{$patient->id}",
                'local'
            );

            $scan->update(['image_path' => $path]);

            Log::info('wound scan image stored', [
                'local_uuid' => $localUuid,
                'patient_id' => $patient->id,
                'bytes' => $request->file('image')->getSize(),
            ]);

            return response()->json([
                'message' => 'Image stored.',
                'local_uuid' => $localUuid,
            ], 201);
        } catch (Throwable $e) {
            Log::error('wound scan image failed', [
                'local_uuid' => $localUuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Could not store the image.'], 500);
        }
    }

    /**
     * Stream a stored wound photograph to an authorised viewer.
     *
     * The file lives on the private disk, so this is the only way to read it.
     * A clinician or admin may view any patient's scan; a patient may view only
     * their own. Anything else is 404 rather than 403 — an unauthorised caller
     * should not learn that the scan exists.
     */
    public function image(Request $request, string $localUuid)
    {
        $user = $request->user();

        $scan = WoundScan::where('local_uuid', $localUuid)->first();

        if (! $scan || ! $scan->image_path) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        // isClinician() is the check the rest of this controller uses for
        // "may see any patient's chart"; reuse it rather than inventing a
        // second, subtly different notion of staff.
        $isStaff = $user && $user->isClinician();

        if (! $isStaff) {
            $patient = $user?->patient()->first();
            if (! $patient || $scan->patient_id !== $patient->id) {
                return response()->json(['message' => 'Not found.'], 404);
            }
        }

        if (! Storage::disk('local')->exists($scan->image_path)) {
            // The row says there is an image but the file is gone. Say so
            // plainly instead of returning a broken stream the dashboard would
            // render as a corrupt image.
            Log::warning('wound scan image missing on disk', [
                'local_uuid' => $localUuid,
                'path' => $scan->image_path,
            ]);

            return response()->json(['message' => 'Image file is missing.'], 410);
        }

        return Storage::disk('local')->response(
            $scan->image_path,
            null,
            ['Cache-Control' => 'private, max-age=3600']
        );
    }

}
