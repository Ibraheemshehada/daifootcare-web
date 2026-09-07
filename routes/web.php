<?php

use App\Http\Controllers\LegalPageController;
use Illuminate\Support\Facades\Route;

/*
 * /support and /privacy are rendered on the server, above the SPA catch-all.
 *
 * The App Store requires a working support URL and a privacy policy URL, and
 * the reviewer — like any crawler that does not execute JavaScript — sees only
 * what the first response contains. Served through the SPA these two returned
 * an empty body with a correct title, which passes a status-code check and is
 * a blank page to a person.
 *
 * They must stay above the catch-all: the pattern below matches them too, and
 * the first matching route wins.
 */
Route::get('/{page}', [LegalPageController::class, 'show'])
    ->whereIn('page', ['support', 'privacy']);

/*
 * Everything that is not /api or /up is the Vue SPA — vue-router owns the URL,
 * so any path must return the same shell for a deep link or a refresh to work.
 */
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|up|storage).*$');
