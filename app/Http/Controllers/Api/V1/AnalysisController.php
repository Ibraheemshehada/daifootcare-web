<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side wound analysis, for patients who chose not to download the models.
 *
 * The models themselves run in a Python sidecar, because PHP has no TFLite
 * runtime. This controller is the part that decides whether the caller is
 * allowed to ask — the sidecar listens on loopback only and authenticates
 * nobody, so this is the only thing standing in front of it.
 */
class AnalysisController extends Controller
{
    public function analyse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'max:25600'], // 25 MB
            // The scale from reference-object calibration, in original-image
            // pixels per cm. Absent means the photo was not calibrated and the
            // result says so rather than pretending to centimetres.
            'pixels_per_cm' => ['nullable', 'numeric', 'gt:0'],
            // Depth cannot be recovered from a 2D photo. It is the clinician's
            // probe measurement or it is nothing; it is never inferred.
            'manual_depth_cm' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'local_uuid' => ['nullable', 'uuid'],
        ]);

        $endpoint = rtrim(config('services.inference.url'), '/').'/analyse';

        $form = [];
        if (isset($data['pixels_per_cm'])) {
            $form['pixels_per_cm'] = (string) $data['pixels_per_cm'];
        }
        if (isset($data['manual_depth_cm'])) {
            $form['manual_depth_cm'] = (string) $data['manual_depth_cm'];
        }

        try {
            $response = Http::timeout(config('services.inference.timeout'))
                ->attach(
                    'image',
                    fopen($data['image']->getRealPath(), 'r'),
                    $data['image']->getClientOriginalName() ?: 'wound.jpg'
                )
                ->post($endpoint, $form);
        } catch (\Throwable $e) {
            // The photo is never logged — it is a picture of a patient's foot.
            Log::error('inference sidecar unreachable', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Analysis is temporarily unavailable. Please try again shortly.',
            ], 503);
        }

        if ($response->status() === 422) {
            return response()->json([
                'message' => 'That image could not be analysed. Please retake the photo.',
            ], 422);
        }

        if (! $response->successful()) {
            Log::error('inference sidecar returned an error', [
                'status' => $response->status(),
            ]);

            return response()->json([
                'message' => 'Analysis failed. Please try again.',
            ], 502);
        }

        $result = $response->json();

        // The photo is deliberately not stored. It was sent to be measured, not
        // to be kept, and holding a patient's wound photograph on the server
        // needs its own consent and its own retention rule — neither of which
        // exists yet. The measurements sync through the normal record path.
        return response()->json([
            'analysis' => $result,
            'local_uuid' => $data['local_uuid'] ?? null,
        ]);
    }
}
