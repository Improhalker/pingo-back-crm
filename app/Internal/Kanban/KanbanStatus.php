<?php

namespace App\Internal\Kanban;

enum KanbanStatus: string
{
    case Todo = 'TODO';
    case InProgress = 'IN_PROGRESS';
    case InReview = 'IN_REVIEW';
    case Done = 'DONE';

    /** Column order of the board. */
    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }
}
