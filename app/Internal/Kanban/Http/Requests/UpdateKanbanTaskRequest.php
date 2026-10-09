<?php

namespace App\Internal\Kanban\Http\Requests;

/** Same fields as creation; an absent description keeps the stored one, null clears it. */
class UpdateKanbanTaskRequest extends StoreKanbanTaskRequest {}
