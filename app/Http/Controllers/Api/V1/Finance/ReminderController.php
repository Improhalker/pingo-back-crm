<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Revenue;
use App\Queries\ReminderQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Internal reminders of open instalments (card 10). Read-only: settling stays an explicit, authorized action. */
class ReminderController extends Controller
{
    public function __invoke(ReminderQuery $reminders): JsonResponse
    {
        Gate::authorize('viewAny', Revenue::class);
        Gate::authorize('viewAny', Expense::class);

        return response()->json(['data' => $reminders->get()]);
    }
}
