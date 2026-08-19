<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a stored measurement could not previously answer.
 *
 * A scan arrived as "2.4 × 1.1 cm" and nothing else, so a clinician had no way
 * to tell a correct measurement from one taken off the printed calibration
 * label — which happened in 16 of 42 small-label photographs before the model
 * was retrained. Three columns close that:
 *
 *   overlay_path   what the model actually measured, drawn on the photograph
 *   pixels_per_cm  what gave the centimetres their units, from the printed ring
 *   tilt_deg       how square the camera was; above 40° measured error triples
 *
 * All nullable: every scan recorded before this exists without them, and a
 * photograph with no ring in it still has a measurement worth keeping — flagged
 * as an estimate rather than discarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wound_scans', function (Blueprint $table) {
            $table->string('overlay_path')->nullable()->after('image_path');
            $table->float('pixels_per_cm')->nullable()->after('overlay_path');
            $table->float('tilt_deg')->nullable()->after('pixels_per_cm');
        });
    }

    public function down(): void
    {
        Schema::table('wound_scans', function (Blueprint $table) {
            $table->dropColumn(['overlay_path', 'pixels_per_cm', 'tilt_deg']);
        });
    }
};
