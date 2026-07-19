<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wound_scans', function (Blueprint $table) {
            // When the photograph arrived, which is not when the scan was
            // captured: the measurements sync first and the image follows
            // whenever there is enough connection to carry it. Nullable because
            // most scans will not have one — uploading is optional, and a
            // participant may decline it.
            $table->timestamp('image_uploaded_at')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('wound_scans', function (Blueprint $table) {
            $table->dropColumn('image_uploaded_at');
        });
    }
};
