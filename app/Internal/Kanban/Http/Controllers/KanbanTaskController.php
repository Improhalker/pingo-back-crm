<?php

namespace App\Internal\Kanban\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Internal\Kanban\Http\Requests\MoveKanbanTaskRequest;
use App\Internal\Kanban\Http\Requests\StoreKanbanTaskRequest;
use App\Internal\Kanban\Http\Requests\UpdateKanbanTaskRequest;
use App\Internal\Kanban\Http\Resources\KanbanTaskResource;
use App\Internal\Kanban\KanbanBoard;
use App\Internal\Kanban\KanbanStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class KanbanTaskController extends Controller
{
    public function __construct(private readonly KanbanBoard $board) {}

    public function index(): AnonymousResourceCollection
    {
        return KanbanTaskResource::collection($this->board->all());
    }

    public function store(StoreKanbanTaskRequest $request): JsonResponse
    {
        $task = $this->board->create($request->validated('title'), $request->validated('description'));

        return (new KanbanTaskResource($task))->response()->setStatusCode(201);
    }

    public function update(UpdateKanbanTaskRequest $request, int $task): KanbanTaskResource
    {
        return new KanbanTaskResource($this->board->update(
            $task,
            $request->validated('title'),
            array_key_exists('description', $request->validated()),
            $request->validated('description'),
        ));
    }

    public function destroy(int $task): JsonResponse
    {
        $this->board->delete($task);

        return response()->json(null, 204);
    }

    /** Responds with the whole board so the client adopts the server's authoritative order. */
    public function move(MoveKanbanTaskRequest $request, int $task): AnonymousResourceCollection
    {
        return KanbanTaskResource::collection($this->board->move(
            $task,
            KanbanStatus::from($request->validated('status')),
            (int) $request->validated('position'),
        ));
    }
}
