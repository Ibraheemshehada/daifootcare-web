<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

/**
 * OTP-based password reset, replacing the Firebase Cloud Function the app used.
 */
class PasswordResetController extends Controller
{
    private const CODE_TTL_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;

    /**
     * Issue a reset code.
     *
     * Always returns 200, whether or not the email exists. Reporting "no such
     * user" here would turn this endpoint into a way to discover which email
     * addresses have accounts.
     */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $data['email'])->first();

        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_codes')->insert([
                'email' => $user->email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                Mail::raw(
                    "Your DiaFootCare password reset code is: {$code}\n\n"
                    ."It expires in ".self::CODE_TTL_MINUTES." minutes. "
                    ."If you did not request this, you can ignore this email.",
                    fn ($m) => $m->to($user->email)->subject('DiaFootCare password reset code')
                );
            } catch (\Throwable $e) {
                // With MAIL_MAILER=log (the default in development) the code
                // lands in storage/logs. Configure SMTP before this ships.
                Log::warning('Password reset mail failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'message' => 'If that email has an account, a reset code has been sent.',
        ]);
    }

    /** Verify the code and set the new password. */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:6'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $row = DB::table('password_reset_codes')
            ->where('email', $data['email'])
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $row || $row->attempts >= self::MAX_ATTEMPTS) {
            return response()->json(['message' => 'This code is invalid or has expired.'], 422);
        }

        if (! Hash::check($data['code'], $row->code_hash)) {
            DB::table('password_reset_codes')->where('id', $row->id)->increment('attempts');

            return response()->json(['message' => 'This code is invalid or has expired.'], 422);
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return response()->json(['message' => 'This code is invalid or has expired.'], 422);
        }

        DB::transaction(function () use ($user, $data, $row) {
            $user->forceFill(['password' => $data['password']])->save();

            // Burn the code, and revoke every existing session: a reset is the
            // one moment where an attacker's live token must not survive.
            DB::table('password_reset_codes')->where('id', $row->id)
                ->update(['consumed_at' => now(), 'updated_at' => now()]);
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Password updated. Please sign in.']);
    }
}
