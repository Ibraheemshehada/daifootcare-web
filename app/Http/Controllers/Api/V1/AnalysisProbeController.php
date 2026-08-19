<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A bench for looking at what the models say about one photograph.
 *
 * Deliberately **writes nothing**. No wound scan, no patient, no device, no
 * engagement event, no stored file. A photograph goes to the inference sidecar
 * and the answer comes straight back, so checking what the model does costs
 * nothing and cannot be mistaken later for a patient record.
 *
 * That last part is the point. Without this, the only way to see how the model
 * reads a photograph was to create a patient, install the app, and take a scan —
 * which then sat in the study data as a real measurement of a real person. The
 * study's own numbers were the price of a sanity check.
 *
 * Admin-only rather than clinician-only: it returns raw probabilities against
 * their thresholds, which is a debugging view, not a clinical one, and reading
 * it as clinical advice would be a mistake the interface cannot prevent.
 */
class AnalysisProbeController extends Controller
{
    public function probe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'image', 'max:25600'], // 25 MB
            // Optional override, for asking "what would it say if the scale
            // were this?" — the sidecar finds the printed ring on its own.
            'pixels_per_cm' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $endpoint = rtrim(config('services.inference.url'), '/').'/analyse';

        $form = [];
        if (isset($data['pixels_per_cm'])) {
            $form['pixels_per_cm'] = (string) $data['pixels_per_cm'];
        }

        try {
            $response = Http::timeout(config('services.inference.timeout'))
                ->attach(
                    'image',
                    fopen($data['image']->getRealPath(), 'r'),
                    $data['image']->getClientOriginalName() ?: 'probe.jpg'
                )
                ->post($endpoint, $form);
        } catch (\Throwable $e) {
            // The photograph itself is never logged: it is a picture of
            // somebody's foot, whoever uploaded it and for whatever reason.
            Log::error('probe: inference sidecar unreachable', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'The analysis service is not responding.',
            ], 503);
        }

        if (! $response->successful()) {
            Log::warning('probe: inference returned an error', [
                'status' => $response->status(),
            ]);

            return response()->json([
                'message' => 'That image could not be analysed.',
            ], 422);
        }

        return response()->json([
            'analysis' => $response->json(),
            // Said in the payload as well as on screen. A caller reading this
            // over the API should not have to infer that nothing was kept.
            'stored' => false,
        ]);
    }
}
