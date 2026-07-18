<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Anonymous participation, keyed to the device rather than to a person.
 *
 * A guest gets a real user row with `is_guest = true` and no email, bound to the
 * `device_uuid` the app already generates. Every existing sync endpoint then
 * works unchanged, because they all resolve the patient from the token.
 */
class GuestController extends Controller
{
    /**
     * Start or resume an anonymous session for a device.
     *
     * Idempotent on `device_uuid`: reopening the app returns a token for the
     * same guest rather than fragmenting one participant's history across
     * several anonymous records.
     */
    public function session(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'uuid'],
            'platform' => ['required', 'string', 'in:ios,android'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'mode' => ['nullable', 'string', 'in:online,offline'],
            'locale' => ['nullable', 'string', 'in:en,ar'],
        ]);

        $existing = User::where('guest_device_uuid', $data['device_uuid'])->first();

        // Once a guest has been claimed, the device belongs to the real account.
        // Handing back an anonymous token would split their record in two.
        if ($existing && $existing->claimed_at) {
            return response()->json([
                'message' => 'This device has been linked to a registered account. Sign in instead.',
            ], 409);
        }

        // A device_uuid already registered to a *real* account must never be
        // downgraded into an anonymous session.
        $claimedElsewhere = Device::where('device_uuid', $data['device_uuid'])
            ->whereHas('user', fn ($q) => $q->where('is_guest', false))
            ->exists();

        if ($claimedElsewhere) {
            return response()->json([
                'message' => 'This device is registered to an account. Sign in instead.',
            ], 409);
        }

        [$user, $device] = DB::transaction(function () use ($data, $existing) {
            $user = $existing;

            if (! $user) {
                $user = new User();
                $user->name = 'Guest participant';
                $user->email = null;
                $user->password = null;
                $user->locale = $data['locale'] ?? 'en';
                $user->role = User::ROLE_PATIENT;
                $user->is_guest = true;
                $user->guest_device_uuid = $data['device_uuid'];
                $user->save();

                Patient::create(['user_id' => $user->id]);
            }

            $device = Device::updateOrCreate(
                ['device_uuid' => $data['device_uuid']],
                [
                    'user_id' => $user->id,
                    'platform' => $data['platform'],
                    'app_version' => $data['app_version'] ?? null,
                    'mode' => $data['mode'] ?? 'offline',
                    'last_seen_at' => now(),
                ]
            );

            return [$user, $device];
        });

        return response()->json([
            'guest' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
                'locale' => $user->locale,
                'patient_id' => $user->patient?->id ?? $user->patient()->value('id'),
            ],
            'device' => $device,
            'token' => $user->createToken('guest:'.$data['device_uuid'])->plainTextToken,
        ], $existing ? 200 : 201);
    }

    /**
     * Convert the current anonymous session into a registered account.
     *
     * The guest's user row is upgraded in place rather than copied into a new
     * one, so every scan, log and analytics event already attached to their
     * patient record carries over by construction — no data migration, and no
     * window where a row belongs to neither account.
     */
    public function claim(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_guest) {
            return response()->json(['message' => 'This session is already a registered account.'], 422);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],   // hashed by the cast
                'is_guest' => false,
                'claimed_at' => now(),
                // guest_device_uuid is kept: it records that this account began
                // as an anonymous session, which the study needs in order to
                // interpret the participant's earliest records.
            ])->save();

            // Every token minted for the anonymous session is revoked, so an old
            // guest token cannot keep writing to a now-identified account.
            $user->tokens()->delete();
        });

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'locale' => $user->locale,
                'patient_id' => $user->patient()->value('id'),
            ],
            'token' => $user->createToken('claimed:'.$user->id)->plainTextToken,
        ]);
    }
}
