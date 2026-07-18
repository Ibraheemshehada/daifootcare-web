<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $patients = Patient::query()
            ->with('user:id,name,email,role,is_guest,guest_device_uuid,claimed_at')
            ->withCount('woundScans')
            // Newest scan first, so the list mirrors clinical attention.
            ->withMax('woundScans as last_scan_at', 'captured_at')
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->string('q')->toString();
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->orderByDesc('last_scan_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($patients);
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        $patient->load(['user:id,name,email,role']);
        $patient->loadCount('woundScans');

        return response()->json(['patient' => $patient]);
    }
}
