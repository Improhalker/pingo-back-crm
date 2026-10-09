<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Runs after authentication. Organization roles are deliberately never consulted. */
class EnsurePlatformAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Gate::forUser($request->user())->allows('platform.manage')) {
            return response()->json(['message' => 'Esta área é exclusiva da administração do PingoFin.', 'code' => 'PLATFORM_FORBIDDEN', 'errors' => (object) [], 'request_id' => $request->attributes->get('request_id')], 403);
        }

        return $next($request);
    }
}
