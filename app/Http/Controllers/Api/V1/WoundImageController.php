<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WoundScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Wound photographs.
 *
 * These are the most sensitive thing this system holds. A wound photograph is
 * identifiable on its own — skin, scars, tattoos, jewellery, sometimes a
 * clinician's hands or a ward in the background — so it is treated differently
 * from the measurements derived from it:
 *
 *  - stored on the private disk, never under public/, and never given a URL
 *    that works without authentication
 *  - streamed through this controller so every read passes an authorisation
 *    check, rather than being guessable from a path
 *  - readable by the patient it belongs to and by clinicians, nobody else
 */
class WoundImageController extends Controller
{
    /**
     * Attach the photograph to a scan that has already synced.
     *
     * Keyed on the scan's local_uuid rather than its server id because the
     * phone knows the former at capture time and the latter only after a
     * successful sync — and the upload has to survive being retried later, on a
     * different connection, after the app has restarted.
     */
    public function store(Request $request, string $localUuid): JsonResponse
    {
        $request->validate([
            // 25 MB matches the analyse endpoint. A phone photo is a few MB;
            // well past that is a mistake or an attempt to fill the disk.
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:25600'],
        ]);

        $scan = WoundScan::where('local_uuid', $localUuid)->first();

        if (! $scan) {
            // The scan itself has not synced yet. Not an error the phone should
            // give up on — record sync and image upload are independent, and
            // ordering between them is not guaranteed on a poor connection.
            return response()->json([
                'message' => 'No scan with that local_uuid yet. Sync the scan first, then retry.',
            ], 409);
        }

        if (! $this->mayWrite($request, $scan)) {
            return response()->json(['message' => 'Not your scan.'], 403);
        }

        // Idempotent: a retry after a timeout must not leave two copies.
        if ($scan->image_path && Storage::disk('private')->exists($scan->image_path)) {
            Storage::disk('private')->delete($scan->image_path);
        }

        $path = $request->file('image')->storeAs(
            "wounds/{$scan->patient_id}",
            $scan->local_uuid.'.'.$request->file('image')->extension(),
            'private'
        );

        $scan->forceFill([
            'image_path' => $path,
            'image_uploaded_at' => now(),
        ])->save();

        return response()->json([
            'local_uuid' => $scan->local_uuid,
            'image_uploaded_at' => $scan->image_uploaded_at,
        ]);
    }

    /**
     * Stream the photograph to someone allowed to see it.
     *
     * Deliberately not a redirect to a storage URL: a redirect leaks a path
     * that then works for anyone who has it, which is exactly what these files
     * must not have.
     */
    public function show(Request $request, WoundScan $scan): StreamedResponse|JsonResponse
    {
        if (! $this->mayRead($request, $scan)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $scan->image_path || ! Storage::disk('private')->exists($scan->image_path)) {
            return response()->json(['message' => 'No image for this scan.'], 404);
        }

        return Storage::disk('private')->response(
            $scan->image_path,
            null,
            [
                // Never cached by a shared proxy, and not written to disk by
                // the browser any longer than it needs to render.
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /** Only the owning patient's own device may attach an image. */
    private function mayWrite(Request $request, WoundScan $scan): bool
    {
        $user = $request->user();

        return $user->isClinician()
            || ($user->patient && $user->patient->id === $scan->patient_id);
    }

    /** Clinicians, and the patient the wound belongs to. */
    private function mayRead(Request $request, WoundScan $scan): bool
    {
        $user = $request->user();

        return $user->isClinician()
            || ($user->patient && $user->patient->id === $scan->patient_id);
    }
}
