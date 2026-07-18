<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived OTP codes for password reset.
 *
 * Replaces the Firebase Cloud Function the app used to call. The code is stored
 * hashed: a leaked database row must not be usable to reset someone's password,
 * and the plaintext only ever exists in the email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            // Throttles brute-forcing a 6-digit code.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
