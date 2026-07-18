<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of what the mobile app collects.
 *
 * Phase 2 shipped wound_scans only, but the app records glucose, medication
 * adherence, daily self-care, quality-of-life and satisfaction scores,
 * appointments, SUS responses and usage analytics. Without these the dashboard
 * shows a fraction of each patient's record and the study cannot be analysed
 * from the server at all.
 *
 * Every table follows the same two rules as wound_scans:
 *   - `local_uuid` is generated on the device at capture time and is unique, so
 *     sync is idempotent and a re-sent batch updates rather than duplicates.
 *   - `patient_id` is derived from the authenticated user, never the request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glucose_readings', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('value_mgdl', 6, 1);
            $table->string('tag')->nullable();       // fasting | post_meal | random
            $table->timestamp('measured_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'measured_at']);
        });

        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('dosage')->nullable();
            $table->unsignedTinyInteger('times_per_day')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index('patient_id');
        });

        Schema::create('medication_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // Nullable: a dose can sync before the medication row that owns it.
            $table->foreignId('medication_id')->nullable()->constrained()->nullOnDelete();
            $table->string('medication_local_uuid')->nullable();
            $table->date('log_date');
            $table->unsignedTinyInteger('dose_index')->default(0);
            $table->boolean('taken')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'log_date']);
        });

        Schema::create('self_care_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // foot_inspection | wash_dry | moisturize | footwear | wound_check
            $table->string('item_key');
            $table->date('log_date');
            $table->timestamp('done_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'log_date']);
        });

        Schema::create('qol_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            // 0-10 sliders, higher = worse.
            $table->unsignedTinyInteger('pain');
            $table->unsignedTinyInteger('mobility');
            $table->unsignedTinyInteger('emotional');
            $table->timestamp('recorded_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'recorded_at']);
        });

        Schema::create('satisfaction_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('ease_of_use');     // 1-5 Likert
            $table->unsignedTinyInteger('usefulness');
            $table->unsignedTinyInteger('would_continue');
            $table->timestamp('recorded_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'recorded_at']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamp('scheduled_at');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'scheduled_at']);
        });

        Schema::create('sus_responses', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();

            // Raw Q1..Q10 are stored, not just the composite, so the study can
            // re-analyse per item. The score is derived, never trusted from the
            // client — a client-side scoring bug would otherwise corrupt the study.
            for ($i = 1; $i <= 10; $i++) {
                $table->unsignedTinyInteger("q{$i}");
            }
            $table->decimal('score', 5, 2)->nullable();

            // Which consent declaration was in force. NULL means the response
            // predates consent versioning, i.e. was collected under the
            // on-device-only promise. The study must keep the two separable.
            $table->unsignedTinyInteger('consent_version')->nullable();

            $table->timestamp('recorded_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['patient_id', 'recorded_at']);
        });

        Schema::create('engagement_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');                       // app_open | feature_open | ...
            $table->string('target')->nullable();
            $table->bigInteger('value')->nullable();      // dwell ms for screen_close
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['patient_id', 'occurred_at']);
            $table->index('name');
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('local_uuid')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('version');
            $table->timestamp('accepted_at');
            $table->string('locale', 5)->nullable();
            $table->boolean('covers_prior')->default(false);
            $table->timestamps();
            $table->index(['patient_id', 'version']);
        });
    }

    public function down(): void
    {
        foreach ([
            'consent_records', 'engagement_events', 'sus_responses', 'appointments',
            'satisfaction_entries', 'qol_entries', 'self_care_logs',
            'medication_logs', 'medications', 'glucose_readings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
