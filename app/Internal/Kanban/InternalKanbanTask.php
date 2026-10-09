<?php

namespace App\Internal\Kanban;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'description', 'status', 'position'])]
class InternalKanbanTask extends Model
{
    protected $table = 'internal_kanban_tasks';

    protected function casts(): array
    {
        return ['status' => KanbanStatus::class, 'position' => 'integer'];
    }
}
