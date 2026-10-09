<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\SessionResource;
use App\Support\CompanyMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): SessionResource|JsonResponse
    {
        // Callbacks run only after the credentials are valid. A company account without exactly one
        // active company is refused before any session is created (ADR-028); masters keep the platform flow.
        $status = null;
        $authenticated = Auth::guard('web')->attemptWhen($request->validated(), function ($user) use (&$status) {
            $status = CompanyMembership::resolve($user)['status'];

            return in_array($status, [CompanyMembership::OK, CompanyMembership::PLATFORM], true);
        });
        if (! $authenticated) {
            if ($status === null) {
                throw ValidationException::withMessages(['email' => ['E-mail ou senha não conferem.']]);
            }

            return response()->json(CompanyMembership::denial($status) + [
                'errors' => (object) [],
                'request_id' => $request->attributes->get('request_id'),
            ], 403);
        }
        $request->session()->regenerate();

        return new SessionResource($request->user()->load('organizations'));
    }

    public function me(Request $request): SessionResource
    {
        return new SessionResource($request->user()->load('organizations'));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['message' => 'Sessão encerrada.']]);
    }
}
