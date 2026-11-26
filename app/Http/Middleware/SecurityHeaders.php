<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth headers set by the application itself, so they hold behind any web server or proxy.
 * API answers are JSON only: nothing may render, frame or load them as a document. A header a route
 * already chose (the public greeting's CSP) is never replaced. X-Frame-Options and Referrer-Policy stay
 * with the web server; repeating them here could send two conflicting values.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        if (! $headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (($request->is('api/*') || $request->is('sanctum/*')) && ! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
        // Only over HTTPS (TLS proxy trusted via TRUSTED_PROXIES); plain-HTTP local development is unaffected.
        if ($request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
