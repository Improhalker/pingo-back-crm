<?php

use Illuminate\Support\Facades\Route;

// A public greeting must not create database sessions or expose diagnostics.
// Only this GET route is stateless; authenticated API middleware is unchanged.
Route::get('/', fn () => response()->view('welcome')->withHeaders([
    'Cache-Control' => 'no-store',
    'X-Robots-Tag' => 'noindex, nofollow',
    'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'self'; font-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'",
]))->withoutMiddleware('web');
