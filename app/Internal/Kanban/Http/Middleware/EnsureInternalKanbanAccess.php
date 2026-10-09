<?php

namespace App\Internal\Kanban\Http\Middleware;

use App\Internal\Kanban\KanbanAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs after authentication. Organization roles are deliberately never consulted. */
class EnsureInternalKanbanAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! KanbanAccess::enabled()) {
            return response()->json(['message' => 'Esta ferramenta interna está desativada.', 'code' => 'INTERNAL_KANBAN_DISABLED'], 403);
        }
        if (! KanbanAccess::allows($request->user())) {
            return response()->json(['message' => 'Você não tem acesso a esta ferramenta interna.', 'code' => 'INTERNAL_KANBAN_FORBIDDEN'], 403);
        }

        return $next($request);
    }
}
