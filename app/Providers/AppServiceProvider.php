<?php

namespace App\Providers;

use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(OrganizationContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // This first-party SPA authenticates only with sessions; no token table or issuer is required.
        Sanctum::getAccessTokenFromRequestUsing(fn (Request $request) => null);
        // Platform authorization (ADR-026): one explicit ability, deliberately no Gate::before.
        Gate::define('platform.manage', fn (User $user) => $user->isPlatformAdministrator());
        RateLimiter::for('platform-write', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('platform-provision', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perMinute(5)->by(hash('sha256', strtolower((string) $request->input('email')).'|'.$request->ip())),
            ];
        });
    }
}
