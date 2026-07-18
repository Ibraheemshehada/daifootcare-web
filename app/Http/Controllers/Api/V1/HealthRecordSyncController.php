<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ConsentRecord;
use App\Models\Device;
use App\Models\EngagementEvent;
use App\Models\GlucoseReading;
use App\Models\Medication;
use App\Models\MedicationLog;
use App\Models\QolEntry;
use App\Models\SatisfactionEntry;
use App\Models\SelfCareLog;
use App\Models\SusResponse;
use App\Models\SyncLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync for every record type the mobile app collects apart from wound scans.
 *
 * One controller rather than nine: the idempotency, authorization and partial-
 * failure behaviour must be identical everywhere, and nine near-copies of that
 * logic is how they drift apart. Each type differs only in its validation rules
 * and how a record maps onto columns, so that is all TYPES declares.
 */
class HealthRecordSyncController extends Controller
{
    private const MAX_BATCH = 100;

    /**
     * type => [model, rules, mapper]
     *
     * `rules` are applied per record (prefixed with `records.*.`).
     * `mapper` turns a validated record into column values.
     */
    private function types(): array
    {
        return [
            'glucose' => [
                'model' => GlucoseReading::class,
                'rules' => [
                    'value_mgdl' => ['required', 'numeric', 'between:0,1000'],
                    'tag' => ['nullable', 'string', 'max:30'],
                    'measured_at' => ['required', 'date'],
                ],
                'map' => fn (array $r) => [
                    'value_mgdl' => $r['value_mgdl'],
                    'tag' => $r['tag'] ?? null,
                    'measured_at' => $r['measured_at'],
                ],
            ],
            'medications' => [
                'model' => Medication::class,
                'rules' => [
                    'name' => ['required', 'string', 'max:255'],
                    'dosage' => ['nullable', 'string', 'max:100'],
                    'times_per_day' => ['nullable', 'integer', 'between:1,24'],
                    'is_active' => ['nullable', 'boolean'],
                ],
                'map' => fn (array $r) => [
                    'name' => $r['name'],
                    'dosage' => $r['dosage'] ?? null,
                    'times_per_day' => $r['times_per_day'] ?? 1,
                    'is_active' => $r['is_active'] ?? true,
                ],
            ],
            'medication-logs' => [
                'model' => MedicationLog::class,
                'rules' => [
                    'medication_local_uuid' => ['nullable', 'uuid'],
                    'log_date' => ['required', 'date'],
                    'dose_index' => ['nullable', 'integer', 'between:0,23'],
                    'taken' => ['nullable', 'boolean'],
                ],
                'map' => function (array $r) {
                    // Resolve the owning medication if it has already synced; a
                    // dose that arrives first stays linked by uuid and is joined later.
                    $medId = null;
                    if (! empty($r['medication_local_uuid'])) {
                        $medId = Medication::where('local_uuid', $r['medication_local_uuid'])->value('id');
                    }

                    return [
                        'medication_id' => $medId,
                        'medication_local_uuid' => $r['medication_local_uuid'] ?? null,
                        'log_date' => $r['log_date'],
                        'dose_index' => $r['dose_index'] ?? 0,
                        'taken' => $r['taken'] ?? true,
                    ];
                },
            ],
            'self-care' => [
                'model' => SelfCareLog::class,
                'rules' => [
                    'item_key' => ['required', 'string', 'max:50'],
                    'log_date' => ['required', 'date'],
                    'done_at' => ['nullable', 'date'],
                ],
                'map' => fn (array $r) => [
                    'item_key' => $r['item_key'],
                    'log_date' => $r['log_date'],
                    'done_at' => $r['done_at'] ?? null,
                ],
            ],
            'qol' => [
                'model' => QolEntry::class,
                'rules' => [
                    'pain' => ['required', 'integer', 'between:0,10'],
                    'mobility' => ['required', 'integer', 'between:0,10'],
                    'emotional' => ['required', 'integer', 'between:0,10'],
                    'recorded_at' => ['required', 'date'],
                ],
                'map' => fn (array $r) => [
                    'pain' => $r['pain'],
                    'mobility' => $r['mobility'],
                    'emotional' => $r['emotional'],
                    'recorded_at' => $r['recorded_at'],
                ],
            ],
            'satisfaction' => [
                'model' => SatisfactionEntry::class,
                'rules' => [
                    'ease_of_use' => ['required', 'integer', 'between:1,5'],
                    'usefulness' => ['required', 'integer', 'between:1,5'],
                    'would_continue' => ['required', 'integer', 'between:1,5'],
                    'recorded_at' => ['required', 'date'],
                ],
                'map' => fn (array $r) => [
                    'ease_of_use' => $r['ease_of_use'],
                    'usefulness' => $r['usefulness'],
                    'would_continue' => $r['would_continue'],
                    'recorded_at' => $r['recorded_at'],
                ],
            ],
            'appointments' => [
                'model' => Appointment::class,
                'rules' => [
                    'title' => ['required', 'string', 'max:255'],
                    'scheduled_at' => ['required', 'date'],
                    'location' => ['nullable', 'string', 'max:255'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                ],
                'map' => fn (array $r) => [
                    'title' => $r['title'],
                    'scheduled_at' => $r['scheduled_at'],
                    'location' => $r['location'] ?? null,
                    'notes' => $r['notes'] ?? null,
                ],
            ],
            'sus' => [
                'model' => SusResponse::class,
                'rules' => array_merge(
                    collect(range(1, 10))
                        ->mapWithKeys(fn ($i) => ["q{$i}" => ['required', 'integer', 'between:1,5']])
                        ->all(),
                    [
                        'recorded_at' => ['required', 'date'],
                        'consent_version' => ['nullable', 'integer', 'between:0,255'],
                    ]
                ),
                'map' => function (array $r) {
                    $answers = [];
                    for ($i = 1; $i <= 10; $i++) {
                        $answers["q{$i}"] = $r["q{$i}"];
                    }

                    return $answers + [
                        // Scored server-side on purpose. A client-side scoring bug
                        // would otherwise write bad numbers into the study data,
                        // and the raw items are right here to recompute from.
                        'score' => SusResponse::scoreFor($answers),
                        'consent_version' => $r['consent_version'] ?? null,
                        'recorded_at' => $r['recorded_at'],
                    ];
                },
            ],
            'engagement' => [
                'model' => EngagementEvent::class,
                'rules' => [
                    'name' => ['required', 'string', 'max:60'],
                    'target' => ['nullable', 'string', 'max:120'],
                    'value' => ['nullable', 'integer'],
                    'occurred_at' => ['required', 'date'],
                ],
                'map' => fn (array $r) => [
                    'name' => $r['name'],
                    'target' => $r['target'] ?? null,
                    'value' => $r['value'] ?? null,
                    'occurred_at' => $r['occurred_at'],
                ],
            ],
            'consents' => [
                'model' => ConsentRecord::class,
                'rules' => [
                    'version' => ['required', 'integer', 'between:0,255'],
                    'accepted_at' => ['required', 'date'],
                    'locale' => ['nullable', 'string', 'max:5'],
                    'covers_prior' => ['nullable', 'boolean'],
                ],
                'map' => fn (array $r) => [
                    'version' => $r['version'],
                    'accepted_at' => $r['accepted_at'],
                    'locale' => $r['locale'] ?? null,
                    'covers_prior' => $r['covers_prior'] ?? false,
                ],
            ],
        ];
    }

    public function sync(Request $request, string $type): JsonResponse
    {
        $types = $this->types();

        if (! isset($types[$type])) {
            return response()->json([
                'message' => "Unknown record type '{$type}'.",
                'supported' => array_keys($types),
            ], 404);
        }

        $config = $types[$type];

        $rules = [
            'device_uuid' => ['required', 'uuid'],
            'batch_uuid' => ['nullable', 'uuid'],
            'records' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'records.*.local_uuid' => ['required', 'uuid'],
        ];
        foreach ($config['rules'] as $field => $fieldRules) {
            $rules["records.*.{$field}"] = $fieldRules;
        }

        $data = $request->validate($rules);
        $user = $request->user();

        $device = Device::where('device_uuid', $data['device_uuid'])
            ->where('user_id', $user->id)
            ->first();

        if (! $device) {
            return response()->json([
                'message' => 'Unknown device for this account. Register the device first.',
            ], 404);
        }

        $patient = $user->patient()->first();

        if (! $patient) {
            return response()->json([
                'message' => 'This account has no patient record to attach data to.',
            ], 422);
        }

        $model = $config['model'];
        $hasDeviceColumn = in_array($type, ['glucose', 'engagement'], true);

        $synced = [];
        $failed = [];

        foreach ($data['records'] as $record) {
            try {
                DB::transaction(function () use ($record, $config, $model, $patient, $device, $hasDeviceColumn, $type) {
                    $values = ($config['map'])($record) + ['patient_id' => $patient->id];

                    if ($hasDeviceColumn) {
                        $values['device_id'] = $device->id;
                    }

                    // Engagement and consent rows are historical facts, not
                    // mutable state, so they carry no synced_at marker.
                    if (! in_array($type, ['engagement', 'consents'], true)) {
                        $values['synced_at'] = now();
                    }

                    $model::updateOrCreate(['local_uuid' => $record['local_uuid']], $values);
                });

                $synced[] = $record['local_uuid'];
            } catch (Throwable $e) {
                Log::error('health-record sync failed', [
                    'type' => $type,
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

        return response()->json(['type' => $type, 'synced' => $synced, 'failed' => $failed]);
    }
}
