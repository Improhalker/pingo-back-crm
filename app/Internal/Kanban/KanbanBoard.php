<?php

namespace App\Internal\Kanban;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Board operations. Every write runs in a transaction that locks all task rows in id order,
 * so concurrent writers are serialized and the last committed write wins on the server state.
 */
class KanbanBoard
{
    /** @return Collection<int, InternalKanbanTask> deterministic: column order, position, id */
    public function all(): Collection
    {
        return InternalKanbanTask::query()->get()
            ->sortBy([
                fn ($a, $b) => $a->status->order() <=> $b->status->order(),
                ['position', 'asc'],
                ['id', 'asc'],
            ])->values();
    }

    public function create(string $title, ?string $description): InternalKanbanTask
    {
        return DB::transaction(function () use ($title, $description) {
            $last = $this->lockedTasks()->where('status', KanbanStatus::Todo)->max('position');

            return InternalKanbanTask::create([
                'title' => $title,
                'description' => $description,
                'status' => KanbanStatus::Todo,
                'position' => $last === null ? 0 : $last + 1,
            ]);
        }, 3);
    }

    public function update(int $id, string $title, bool $hasDescription, ?string $description): InternalKanbanTask
    {
        return DB::transaction(function () use ($id, $title, $hasDescription, $description) {
            $task = $this->find($this->lockedTasks(), $id);
            $task->title = $title;
            if ($hasDescription) {
                $task->description = $description;
            }
            $task->save();

            return $task;
        }, 3);
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $tasks = $this->lockedTasks();
            $task = $this->find($tasks, $id);
            $task->delete();
            $this->renumber($this->column($tasks, $task->status)->reject(fn ($t) => $t->id === $id)->values());
        }, 3);
    }

    /** Moves a task to `$position` (0-based) of column `$status`, renumbering the affected columns densely. */
    public function move(int $id, KanbanStatus $status, int $position): Collection
    {
        DB::transaction(function () use ($id, $status, $position) {
            $tasks = $this->lockedTasks();
            $task = $this->find($tasks, $id);
            $origin = $task->status;

            $target = $this->column($tasks, $status)->reject(fn ($t) => $t->id === $id)->values();
            if ($position > $target->count()) {
                throw ValidationException::withMessages(['position' => ['Posição fora da coluna.']]);
            }
            $target->splice($position, 0, [$task]);
            $this->renumber($target, $status, $id);
            if ($origin !== $status) {
                $this->renumber($this->column($tasks, $origin)->reject(fn ($t) => $t->id === $id)->values());
            }
        }, 3);

        return $this->all();
    }

    /** @return Collection<int, InternalKanbanTask> */
    private function lockedTasks(): Collection
    {
        return InternalKanbanTask::query()->orderBy('id')->lockForUpdate()->get();
    }

    private function find(Collection $tasks, int $id): InternalKanbanTask
    {
        return $tasks->firstWhere('id', $id)
            ?? throw (new ModelNotFoundException)->setModel(InternalKanbanTask::class, [$id]);
    }

    private function column(Collection $tasks, KanbanStatus $status): Collection
    {
        return $tasks->where('status', $status)->sortBy([['position', 'asc'], ['id', 'asc']])->values();
    }

    /** Writes dense positions; neighbours change without touching updated_at. The moved task is saved normally. */
    private function renumber(iterable $column, ?KanbanStatus $status = null, ?int $movedId = null): void
    {
        foreach ($column as $index => $task) {
            if ($task->id === $movedId) {
                $task->status = $status;
                $task->position = $index;
                $task->save();
            } elseif ($task->position !== $index) {
                InternalKanbanTask::query()->whereKey($task->id)->toBase()->update(['position' => $index]);
            }
        }
    }
}
