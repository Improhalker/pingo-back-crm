<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContextController;
use App\Http\Controllers\Api\V1\Finance\CategoryController;
use App\Http\Controllers\Api\V1\Finance\CustomerController;
use App\Http\Controllers\Api\V1\Finance\DashboardController;
use App\Http\Controllers\Api\V1\Finance\ExpenseController;
use App\Http\Controllers\Api\V1\Finance\ReminderController;
use App\Http\Controllers\Api\V1\Finance\ReportController;
use App\Http\Controllers\Api\V1\Finance\RevenueController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Platform\AuthController as PlatformAuthController;
use App\Http\Controllers\Api\V1\Platform\OrganizationController as PlatformOrganizationController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::withoutMiddleware([EnsureFrontendRequestsAreStateful::class])->group(function () {
    Route::get('health/live', [HealthController::class, 'live']);
    Route::get('health', [HealthController::class, 'ready']);
});
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::middleware('auth:sanctum')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('context', ContextController::class)->middleware('organization');
});

// Company data (tenant-owned): always inside the authorized organization context (X-Organization-Id).
// Platform administrators have no membership and therefore no access here (ADR-026).
Route::middleware(['auth:sanctum', 'organization'])->group(function () {
    Route::get('dashboard', DashboardController::class);
    Route::get('customers', [CustomerController::class, 'index']);
    Route::post('customers', [CustomerController::class, 'store']);
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer');
    Route::post('customers/{customer}/archive', [CustomerController::class, 'archive'])->whereNumber('customer');
    Route::post('customers/{customer}/unarchive', [CustomerController::class, 'unarchive'])->whereNumber('customer');

    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::get('categories/{category}', [CategoryController::class, 'show'])->whereNumber('category');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->whereNumber('category');
    Route::post('categories/{category}/archive', [CategoryController::class, 'archive'])->whereNumber('category');
    Route::post('categories/{category}/unarchive', [CategoryController::class, 'unarchive'])->whereNumber('category');

    Route::get('revenues', [RevenueController::class, 'index']);
    Route::post('revenues', [RevenueController::class, 'store']);
    Route::patch('revenues/{revenue}', [RevenueController::class, 'update'])->whereNumber('revenue');
    Route::delete('revenues/{revenue}', [RevenueController::class, 'destroy'])->whereNumber('revenue');
    Route::get('revenues/{revenue}', [RevenueController::class, 'show'])->whereNumber('revenue');
    Route::post('receivables/{receivable}/receipts', [RevenueController::class, 'storeReceipt'])->whereNumber('receivable');
    Route::post('receipts/{receipt}/corrections', [RevenueController::class, 'correctReceipt'])->whereNumber('receipt');

    Route::get('expenses', [ExpenseController::class, 'index']);
    Route::post('expenses', [ExpenseController::class, 'store']);
    Route::patch('expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense');
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->whereNumber('expense');
    Route::post('payables/{payable}/payments', [ExpenseController::class, 'storePayment'])->whereNumber('payable');
    Route::post('payments/{payment}/corrections', [ExpenseController::class, 'correctPayment'])->whereNumber('payment');
    Route::get('reminders', ReminderController::class);
    Route::get('reports/monthly', [ReportController::class, 'monthly']);
    Route::get('reports/statement', [ReportController::class, 'statement']);
    Route::get('reports/obligations', [ReportController::class, 'obligations']);
});

// Platform administration (ADR-026). Own authorization; deliberately without the tenant 'organization' middleware.
Route::prefix('platform')->group(function () {
    Route::post('auth/login', [PlatformAuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', 'platform'])->group(function () {
        Route::get('organizations', [PlatformOrganizationController::class, 'index']);
        Route::post('organizations', [PlatformOrganizationController::class, 'store'])->middleware('throttle:platform-provision');
        Route::get('organizations/{organization}', [PlatformOrganizationController::class, 'show'])->whereNumber('organization');
        Route::middleware('throttle:platform-write')->whereNumber('organization')->group(function () {
            Route::patch('organizations/{organization}', [PlatformOrganizationController::class, 'update']);
            Route::delete('organizations/{organization}', [PlatformOrganizationController::class, 'destroy']);
            Route::post('organizations/{organization}/suspend', [PlatformOrganizationController::class, 'suspend']);
            Route::post('organizations/{organization}/reactivate', [PlatformOrganizationController::class, 'reactivate']);
            Route::post('organizations/{organization}/administrators', [PlatformOrganizationController::class, 'storeAdministrator'])->middleware('throttle:platform-provision');
        });
    });
});

// Internal project Kanban (temporary tool). Remove this line and app/Internal/Kanban to retire it.
require __DIR__.'/internal-kanban.php';
