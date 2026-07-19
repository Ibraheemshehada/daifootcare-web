<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the TFLite bundle for Offline mode.
 *
 * Deliberately **public**: a phone choosing Offline mode at first launch has not
 * necessarily signed in yet, and the models are the same build shipped inside
 * every APK — there is nothing patient-specific to protect here.
 */
class ModelController extends Controller
{
    /** Where the .tflite files live on the server. */
    private function modelPath(string $file = ''): string
    {
        return storage_path('app/models'.($file !== '' ? '/'.$file : ''));
    }

    /**
     * The bundle description a client downloads before fetching anything.
     *
     * Checksums are computed from the files on disk, never hand-written — a
     * manifest that disagrees with its own files would have every download fail
     * verification with no way to tell which side was wrong.
     *
     * Cached because sha256 over 175 MB is not something to repeat per request;
     * the cache key includes each file's mtime and size, so replacing a model
     * invalidates it without anyone remembering to flush.
     */
    public function manifest(Request $request): JsonResponse
    {
        $dir = $this->modelPath();

        if (! is_dir($dir)) {
            return response()->json([
                'message' => 'No model bundle is published on this server.',
            ], 503);
        }

        $files = collect(glob($dir.'/*.{tflite,json}', GLOB_BRACE))
            ->filter(fn ($p) => is_file($p))
            ->values();

        if ($files->isEmpty()) {
            return response()->json([
                'message' => 'No model bundle is published on this server.',
            ], 503);
        }

        $fingerprint = $files
            ->map(fn ($p) => basename($p).':'.filesize($p).':'.filemtime($p))
            ->implode('|');

        $manifest = Cache::rememberForever(
            'models.manifest.'.sha1($fingerprint),
            function () use ($files, $request) {
                $entries = $files->map(fn ($path) => [
                    'name' => basename($path),
                    'bytes' => filesize($path),
                    'sha256' => hash_file('sha256', $path),
                    'url' => url("/api/v1/models/file/".basename($path)),
                ])->all();

                return [
                    // Derived from the file set, so two servers holding the same
                    // files report the same version and a scan's models_version
                    // means something across installs.
                    'version' => substr(sha1(collect($entries)->pluck('sha256')->implode('')), 0, 12),
                    'total_bytes' => collect($entries)->sum('bytes'),
                    'files' => $entries,
                ];
            }
        );

        return response()->json($manifest);
    }

    /**
     * One model file.
     *
     * Returned via BinaryFileResponse, which implements HTTP Range: that is what
     * lets a 175 MB download resume from where a dropped connection left it
     * rather than starting again. At this size on a clinic's wifi, a
     * non-resumable download is one that never finishes.
     */
    public function file(Request $request, string $name): BinaryFileResponse|Response
    {
        // Basename only: the name comes from the URL, and a traversal here would
        // serve arbitrary files off the server.
        $safe = basename($name);

        if (! preg_match('/^[A-Za-z0-9._-]+\.(tflite|json)$/', $safe)) {
            abort(404);
        }

        $path = $this->modelPath($safe);

        if (! is_file($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => str_ends_with($safe, '.json')
                ? 'application/json'
                : 'application/octet-stream',
            // Lets a CDN or the client cache aggressively; the filename changes
            // when the model does, and the manifest carries the checksum.
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
