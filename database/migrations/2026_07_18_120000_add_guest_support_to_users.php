<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest participants.
 *
 * The app has a Guest login, and guests generate scans, self-care logs and
 * usage analytics like anyone else. Until now none of it could sync: every
 * endpoint derives the patient from an authenticated account, so guest activity
 * simply never reached the study.
 *
 * A guest is a real (anonymous) user row keyed to the device's `device_uuid` —
 * the UUID the app already generates and stores locally. Deliberately NOT a
 * hardware identifier:
 *
 *   - MAC address is unavailable on Android 6+ (apps get 02:00:00:00:00:00) and
 *     Play policy forbids non-resettable hardware IDs for tracking.
 *   - Location is precise personal data and wildly disproportionate as an
 *     identity key for a health app.
 *
 * `device_uuid` is app-scoped and disappears when the user uninstalls, which is
 * the behaviour a participant would expect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_guest')->default(false)->after('role');

            // The device this anonymous account belongs to. Null for real accounts.
            $table->uuid('guest_device_uuid')->nullable()->unique()->after('is_guest');

            // Set when a guest later registers and their history is carried over.
            $table->timestamp('claimed_at')->nullable()->after('guest_device_uuid');
            $table->foreignId('claimed_by_user_id')->nullable()->after('claimed_at')
                ->constrained('users')->nullOnDelete();

            $table->index('is_guest');
        });

        // A guest has no email, so the unique constraint on a shared placeholder
        // would collide on the second guest. Email becomes nullable instead.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_user_id');
            $table->dropIndex(['is_guest']);
            $table->dropColumn(['is_guest', 'guest_device_uuid', 'claimed_at']);
        });
    }
};
