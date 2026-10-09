<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\DashboardRequest;
use App\Queries\DashboardQuery;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, DashboardQuery $dashboard, OrganizationContext $context): JsonResponse
    {
        Gate::authorize('viewDashboard', $context->organization());

        return response()->json(['data' => $dashboard->get($request->validated('month'))]);
    }
}
