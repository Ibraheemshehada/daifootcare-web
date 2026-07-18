<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily rollups of high-volume engagement events.
 *
 * One emulator produced 23,000 raw events in a day of testing, overwhelmingly
 * screen opens and closes. At study scale that buries the clinical data in both
 * the database and the sync traffic.
 *
 * Aggregating rather than sampling is deliberate: every figure the study
 * actually computes from analytics is a COUNT, a SUM or a DISTINCT date — there
 * is no query anywhere that reads an individual event row. A daily rollup
 * therefore reproduces those numbers **exactly**, where sampling would only
 * estimate them. Precision is not the thing being traded away; per-event
 * ordering is, and nothing reads that.
 *
 * Low-volume, analytically rich events (app_open, task_start, task_complete,
 * error) keep syncing raw — see EngagementEvent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_daily', function (Blueprint $table) {
            $table->id();

            // Derived deterministically on the device from date+name+target, so
            // re-sending a day whose counts have grown updates the same row
            // instead of appending a second copy of that day.
            $table->uuid('local_uuid')->unique();

            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();

            $table->date('day');
            $table->string('name');              // screen_open | screen_close | ...
            $table->string('target')->nullable();

            $table->unsignedInteger('event_count')->default(0);
            // Sum of the numeric payload — dwell milliseconds for screen_close.
            // Kept alongside the count so mean time-on-task stays computable.
            $table->bigInteger('total_value')->nullable();

            $table->timestamps();

            $table->index(['patient_id', 'day']);
            $table->index(['name', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_daily');
    }
};
