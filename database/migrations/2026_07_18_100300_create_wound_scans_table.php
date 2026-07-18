<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wound_scans', function (Blueprint $table) {
            $table->id();

            // Generated on the device at CAPTURE time (not upload time). This is the
            // idempotency key: the sync endpoint upserts on it, so a batch that is
            // re-sent after a half-failed request can never duplicate a clinical record.
            $table->uuid('local_uuid')->unique();

            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('captured_at');

            // Model 1 — segmentation. Nullable: a scan can legitimately find no wound.
            $table->decimal('length_cm', 6, 2)->nullable();
            $table->decimal('width_cm', 6, 2)->nullable();
            $table->decimal('area_cm2', 8, 2)->nullable();
            $table->decimal('depth_cm', 6, 2)->nullable();   // manual probe entry
            $table->boolean('is_calibrated')->default(false);

            // Model 2 — tissue classification (per-class probabilities).
            $table->json('tissue_json')->nullable();

            // Model 3 — infection / ischaemia.
            $table->boolean('infection_present')->nullable();
            $table->decimal('infection_prob', 5, 4)->nullable();
            $table->boolean('ischaemia_present')->nullable();
            $table->decimal('ischaemia_prob', 5, 4)->nullable();
            $table->string('risk_badge')->nullable();

            $table->string('image_path')->nullable();

            // Which model build produced this result. Without it, a Mode A (server)
            // result and a Mode B (on-device) result are not comparable, and a device
            // running an older downloaded bundle is indistinguishable from a current one.
            $table->string('models_version')->nullable();
            $table->string('source')->default('offline');  // offline | online

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'captured_at']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wound_scans');
    }
};
