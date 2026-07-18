<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'locale' => ['nullable', 'string', 'in:en,ar'],
        ]);

        // Self-registration always creates a patient. Promoting to doctor/admin is an
        // admin-side action — never something the request body can ask for.
        $user = DB::transaction(function () use ($data) {
            $user = new User();
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'en',
            ]);
            $user->role = User::ROLE_PATIENT;
            $user->save();

            Patient::create(['user_id' => $user->id]);

            return $user;
        });

        return response()->json([
            'user' => $this->userPayload($user->fresh('patient')),
            'token' => $user->createToken($this->tokenName($request))->plainTextToken,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // One generic message for both "no such user" and "wrong password" so the
        // endpoint can't be used to enumerate which emails have accounts.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        return response()->json([
            'user' => $this->userPayload($user->load('patient')),
            'token' => $user->createToken($this->tokenName($request))->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke only the token that made this call, so logging out on the phone
        // does not sign the user out of the dashboard.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()->load('patient')),
        ]);
    }

    private function tokenName(Request $request): string
    {
        return substr($request->input('device_name', $request->userAgent() ?? 'api'), 0, 255);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'locale' => $user->locale,
            'patient_id' => $user->patient?->id,
        ];
    }
}
