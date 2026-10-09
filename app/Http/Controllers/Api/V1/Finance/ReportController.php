<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\CashStatementRequest;
use App\Http\Requests\Finance\MonthlyReportRequest;
use App\Http\Requests\Finance\OpenObligationsRequest;
use App\Queries\Reports\CashStatementQuery;
use App\Queries\Reports\MonthlyReportQuery;
use App\Queries\Reports\OpenObligationsQuery;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Read-only reports of the authorized company (card 11, ADR-033). Totals cover the whole filtered set. */
class ReportController extends Controller
{
    public function __construct(private OrganizationContext $context) {}

    public function monthly(MonthlyReportRequest $request, MonthlyReportQuery $report): JsonResponse
    {
        Gate::authorize('viewReports', $this->context->organization());
        [$from, $to] = $request->months();

        return response()->json(['data' => $report->get($from, $to)]);
    }

    public function statement(CashStatementRequest $request, CashStatementQuery $report): JsonResponse
    {
        Gate::authorize('viewReports', $this->context->organization());

        return response()->json($report->get($request->filters(), $request->page(), $request->perPage()));
    }

    public function obligations(OpenObligationsRequest $request, OpenObligationsQuery $report): JsonResponse
    {
        Gate::authorize('viewReports', $this->context->organization());

        return response()->json($report->get($request->filters(), $request->page(), $request->perPage()));
    }
}
