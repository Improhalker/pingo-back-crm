<?php

namespace App\Internal\Kanban\Http\Requests;

use App\Internal\Kanban\KanbanStatus;
use Illuminate\Validation\Rule;

class MoveKanbanTaskRequest extends KanbanRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(KanbanStatus::class)],
            // Zero-based index in the destination column, counted without the moved task.
            'position' => ['required', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
