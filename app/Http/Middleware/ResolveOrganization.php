<?php

namespace App\Http\Middleware;

use App\Support\CompanyMembership;
use App\Support\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the request's organization context from the account's single membership (ADR-028).
 * X-Organization-Id is optional: when sent, it must match that membership, so a forged or stale
 * selector never grants access (404, without revealing whether the organization exists).
 */
class ResolveOrganization
{
    public function __construct(private OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->header('X-Organization-Id');
        if ($id !== null && (! is_string($id) || ! preg_match('/^[1-9][0-9]{0,17}$/', $id))) {
            throw ValidationException::withMessages(['organization' => ['Organização inválida.']]);
        }

        $resolved = CompanyMembership::resolve($request->user());
        $organization = $resolved['organization'];
        if ($organization !== null && $id !== null && (string) $organization->id !== $id) {
            abort(404);
        }
        // Suspension is decided by the platform; members keep their link but cannot open the context.
        if ($resolved['status'] !== CompanyMembership::OK) {
            return response()->json(CompanyMembership::denial($resolved['status']) + [
                'errors' => (object) [],
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }

        $this->context->set($organization);
        try {
            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
