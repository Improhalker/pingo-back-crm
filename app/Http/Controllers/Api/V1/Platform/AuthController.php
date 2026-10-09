<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\SessionResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Entry point of the platform area. It reuses the Sanctum session guard but authenticates only
 * platform administrators. A valid company account gets the same generic failure as a wrong password,
 * and an existing session is left untouched.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): SessionResource
    {
        $authenticated = Auth::guard('web')->attemptWhen(
            $request->validated(),
            fn ($user) => $user->isPlatformAdministrator(),
        );
        if (! $authenticated) {
            throw ValidationException::withMessages(['email' => ['E-mail ou senha não conferem.']]);
        }
        $request->session()->regenerate();

        return new SessionResource($request->user()->load('organizations'));
    }
}
