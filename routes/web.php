<?php

use Illuminate\Support\Facades\Route;

/*
 * Everything that is not /api or /up is the Vue SPA — vue-router owns the URL,
 * so any path must return the same shell for a deep link or a refresh to work.
 */
Route::get('/{any}', function () {
    return view('app');
})->where('any', '^(?!api|up|storage).*$');
