<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates aggregate/clinical-oversight endpoints to admins and doctors.
 *
 * Patients authenticate against the same API from the mobile app, so without this
 * a patient token could read fleet-wide counts and other people's records.
 */
class EnsureUserIsClinician
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isClinician()) {
            abort(403, 'This area is restricted to clinical staff.');
        }

        return $next($request);
    }
}
