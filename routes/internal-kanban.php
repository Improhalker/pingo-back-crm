<?php

use App\Internal\Kanban\Http\Controllers\KanbanTaskController;
use App\Internal\Kanban\Http\Middleware\EnsureInternalKanbanAccess;
use Illuminate\Support\Facades\Route;

// Internal project tool, independent of organizations. Included from routes/api.php (prefix api/v1).
Route::middleware(['auth:sanctum', EnsureInternalKanbanAccess::class])
    ->prefix('internal/kanban')
    ->whereNumber('task')
    ->group(function () {
        Route::get('tasks', [KanbanTaskController::class, 'index']);
        Route::post('tasks', [KanbanTaskController::class, 'store']);
        Route::patch('tasks/{task}', [KanbanTaskController::class, 'update']);
        Route::delete('tasks/{task}', [KanbanTaskController::class, 'destroy']);
        Route::post('tasks/{task}/move', [KanbanTaskController::class, 'move']);
    });
