<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Client-generated, stable across reinstalls of the same install session.
            $table->uuid('device_uuid')->unique();

            $table->string('platform');            // ios | android
            $table->string('app_version')->nullable();
            $table->string('mode')->default('online'); // online | offline

            // Set once the ~208MB TFLite bundle finishes downloading in Mode B.
            $table->timestamp('models_downloaded_at')->nullable();
            $table->string('models_version')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
