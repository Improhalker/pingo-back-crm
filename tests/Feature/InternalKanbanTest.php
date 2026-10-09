<?php

namespace Tests\Feature;

use App\Internal\Kanban\InternalKanbanTask;
use App\Internal\Kanban\KanbanAccess;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class KanbanStrictCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

class InternalKanbanTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/internal/kanban/tasks';

    private function enable(): void
    {
        config(['internal_kanban.enabled' => true]);
    }

    private function internalUser(string $email = 'internal@test.invalid'): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->platformAdministrator()->create();

        return $user;
    }

    private function task(string $title, string $status = 'TODO', int $position = 0, ?string $description = null): InternalKanbanTask
    {
        return InternalKanbanTask::create(compact('title', 'status', 'position', 'description'));
    }

    /** @return array<string, list<string>> titles by status as the API returns them */
    private function board(): array
    {
        $board = [];
        foreach ($this->getJson(self::BASE)->assertOk()->json('data') as $task) {
            $board[$task['status']][] = $task['title'];
        }

        return $board;
    }

    private function positions(): array
    {
        return InternalKanbanTask::query()->orderBy('status')->orderBy('position')->get()
            ->groupBy(fn ($t) => $t->status->value)->map(fn ($g) => $g->pluck('position')->all())->all();
    }

    public function test_visitor_cannot_access_the_board(): void
    {
        $this->enable();
        $this->getJson(self::BASE)->assertUnauthorized();
        $this->postJson(self::BASE, ['title' => 'x'])->assertUnauthorized();
        $this->patchJson(self::BASE.'/1', ['title' => 'x'])->assertUnauthorized();
        $this->deleteJson(self::BASE.'/1')->assertUnauthorized();
        $this->postJson(self::BASE.'/1/move', ['status' => 'DONE', 'position' => 0])->assertUnauthorized();
    }

    public function test_feature_is_disabled_by_default_and_blocks_every_operation(): void
    {
        $this->assertFalse(KanbanAccess::enabled());
        $task = $this->task('Existing');
        $this->actingAs($this->internalUser(), 'web');
        $this->getJson(self::BASE)->assertForbidden()->assertJsonPath('code', 'INTERNAL_KANBAN_DISABLED');
        $this->postJson(self::BASE, ['title' => 'x'])->assertForbidden();
        $this->patchJson(self::BASE.'/'.$task->id, ['title' => 'x'])->assertForbidden();
        $this->deleteJson(self::BASE.'/'.$task->id)->assertForbidden();
        $this->postJson(self::BASE.'/'.$task->id.'/move', ['status' => 'DONE', 'position' => 0])->assertForbidden();
        $this->assertSame('Existing', $task->fresh()->title);
        $this->assertDatabaseCount('internal_kanban_tasks', 1);
    }

    public function test_master_without_organization_or_legacy_allowlist_can_access(): void
    {
        $this->enable();
        config(['internal_kanban.allowed_emails' => '']);
        $user = $this->internalUser();
        $this->assertSame(0, $user->organizations()->count());
        $this->actingAs($user, 'web')->getJson(self::BASE)->assertOk();
    }

    public function test_non_master_even_in_legacy_allowlist_is_denied_every_operation(): void
    {
        $this->enable();
        config(['internal_kanban.allowed_emails' => 'internal@test.invalid']);
        $task = $this->task('Existing');
        $this->actingAs(User::factory()->create(['email' => 'internal@test.invalid']), 'web');
        $this->getJson(self::BASE)->assertForbidden()->assertJsonPath('code', 'INTERNAL_KANBAN_FORBIDDEN');
        $this->postJson(self::BASE, ['title' => 'x'])->assertForbidden();
        $this->patchJson(self::BASE.'/'.$task->id, ['title' => 'x'])->assertForbidden();
        $this->deleteJson(self::BASE.'/'.$task->id)->assertForbidden();
        $this->postJson(self::BASE.'/'.$task->id.'/move', ['status' => 'DONE', 'position' => 0])->assertForbidden();
        $this->assertSame('Existing', $task->fresh()->title);
        $this->assertDatabaseCount('internal_kanban_tasks', 1);
    }

    public function test_organization_admin_does_not_gain_access_from_the_role(): void
    {
        $this->enable();
        $user = User::factory()->create(['email' => 'admin@test.invalid']);
        $user->organizations()->attach(Organization::create(['name' => 'Synthetic A']), ['role' => 'ADMIN']);
        $this->actingAs($user, 'web');
        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE, ['title' => 'x'])->assertForbidden();
    }

    public function test_organization_operator_does_not_gain_access_from_the_role(): void
    {
        $this->enable();
        $user = User::factory()->create();
        $user->organizations()->attach(Organization::create(['name' => 'Synthetic A']), ['role' => 'OPERATOR']);
        $this->actingAs($user, 'web');
        $this->getJson(self::BASE)->assertForbidden();
        $this->postJson(self::BASE, ['title' => 'x'])->assertForbidden();
    }

    public function test_client_cannot_claim_platform_master_access(): void
    {
        $this->enable();
        $this->actingAs(User::factory()->create(['email' => 'intruder@test.invalid']), 'web');
        $this->withHeaders(['X-Platform-Administrator' => 'true', 'X-Internal-Kanban-Allowed-Emails' => 'intruder@test.invalid'])
            ->postJson(self::BASE, ['title' => 'x', 'is_platform_administrator' => true, 'allowed_emails' => ['intruder@test.invalid']])
            ->assertForbidden()->assertJsonPath('code', 'INTERNAL_KANBAN_FORBIDDEN');
        $this->assertDatabaseCount('internal_kanban_tasks', 0);
    }

    public function test_revoking_master_access_blocks_an_already_authenticated_session(): void
    {
        $this->enable();
        $user = $this->internalUser();
        $this->actingAs($user, 'web')->getJson(self::BASE)->assertOk();
        $user->platformAdministrator()->delete();
        $this->getJson(self::BASE)->assertForbidden()->assertJsonPath('code', 'INTERNAL_KANBAN_FORBIDDEN');
        $this->postJson(self::BASE, ['title' => 'x'])->assertForbidden();
        $this->assertDatabaseCount('internal_kanban_tasks', 0);
    }

    public function test_authorized_user_can_create_edit_move_and_delete(): void
    {
        $this->enable();
        $this->actingAs($this->internalUser(), 'web');
        $this->getJson(self::BASE)->assertOk()->assertExactJson(['data' => []]);

        $id = $this->postJson(self::BASE, ['title' => '  Primeira  ', 'description' => 'Texto <b>simples</b>'])
            ->assertCreated()->assertJsonPath('data.title', 'Primeira')->assertJsonPath('data.status', 'TODO')
            ->assertJsonPath('data.position', 0)->assertJsonPath('data.description', 'Texto <b>simples</b>')->json('data.id');
        $this->postJson(self::BASE, ['title' => 'Segunda'])->assertCreated()->assertJsonPath('data.position', 1);

        $this->patchJson(self::BASE.'/'.$id, ['title' => 'Primeira editada'])->assertOk()
            ->assertJsonPath('data.title', 'Primeira editada')->assertJsonPath('data.description', 'Texto <b>simples</b>');
        $this->patchJson(self::BASE.'/'.$id, ['title' => 'Primeira editada', 'description' => null])->assertOk()->assertJsonPath('data.description', null);

        $this->postJson(self::BASE.'/'.$id.'/move', ['status' => 'IN_PROGRESS', 'position' => 0])->assertOk();
        $this->assertSame(['TODO' => ['Segunda'], 'IN_PROGRESS' => ['Primeira editada']], $this->board());

        $this->deleteJson(self::BASE.'/'.$id)->assertNoContent();
        $this->assertSame(['TODO' => ['Segunda']], $this->board());
        $this->deleteJson(self::BASE.'/'.$id)->assertNotFound();
        $this->patchJson(self::BASE.'/999999', ['title' => 'x'])->assertNotFound();
        $this->postJson(self::BASE.'/999999/move', ['status' => 'DONE', 'position' => 0])->assertNotFound();
    }

    public function test_invalid_data_is_rejected_without_writing(): void
    {
        $this->enable();
        $this->actingAs($this->internalUser(), 'web');
        $task = $this->task('Keep', 'TODO', 0, 'Original');
        foreach ([
            [],
            ['title' => ''],
            ['title' => '   '],
            ['title' => str_repeat('a', 121)],
            ['title' => ['array']],
            ['title' => 'ok', 'description' => str_repeat('d', 2001)],
            ['title' => 'ok', 'status' => 'DONE'],
            ['title' => 'ok', 'position' => 3],
            ['title' => 'ok', 'id' => 99],
        ] as $payload) {
            $this->postJson(self::BASE, $payload)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->patchJson(self::BASE.'/'.$task->id, ['title' => ''])->assertUnprocessable();
        $this->patchJson(self::BASE.'/'.$task->id, ['title' => 'x', 'status' => 'DONE'])->assertUnprocessable();
        $this->postJson(self::BASE, ['title' => str_repeat('a', 120), 'description' => str_repeat('d', 2000)])->assertCreated();
        foreach ([
            [], ['status' => 'DONE'], ['position' => 0], ['status' => 'ARCHIVED', 'position' => 0],
            ['status' => 'done', 'position' => 0], ['status' => 'DONE', 'position' => -1], ['status' => 'DONE', 'position' => 'a'],
            ['status' => 'DONE', 'position' => 1.5], ['status' => 'DONE', 'position' => 5], ['status' => 'DONE', 'position' => 0, 'title' => 'x'],
        ] as $payload) {
            $this->postJson(self::BASE.'/'.$task->id.'/move', $payload)->assertUnprocessable();
        }
        $this->postJson(self::BASE.'/abc/move', ['status' => 'DONE', 'position' => 0])->assertNotFound();
        $fresh = $task->fresh();
        $this->assertSame(['Keep', 'Original', 'TODO', 0], [$fresh->title, $fresh->description, $fresh->status->value, $fresh->position]);
        $this->assertDatabaseCount('internal_kanban_tasks', 2);
    }

    public function test_moving_and_reordering_persist_densely_without_losing_tasks(): void
    {
        $this->enable();
        $this->actingAs($this->internalUser(), 'web');
        $a = $this->task('A', 'TODO', 0, 'desc A');
        $b = $this->task('B', 'TODO', 1);
        $c = $this->task('C', 'TODO', 2);
        $d = $this->task('D', 'DONE', 0);

        // Reorder inside a column: C to the front.
        $this->postJson(self::BASE.'/'.$c->id.'/move', ['status' => 'TODO', 'position' => 0])->assertOk();
        $this->assertSame(['TODO' => ['C', 'A', 'B'], 'DONE' => ['D']], $this->board());
        // Move between columns, inserted in the middle, source column is compacted.
        $this->postJson(self::BASE.'/'.$a->id.'/move', ['status' => 'DONE', 'position' => 1])->assertOk();
        $this->assertSame(['TODO' => ['C', 'B'], 'DONE' => ['D', 'A']], $this->board());
        $this->assertEquals(['DONE' => [0, 1], 'TODO' => [0, 1]], $this->positions());
        // Move to the end of an empty column and back to the end of the same column.
        $this->postJson(self::BASE.'/'.$b->id.'/move', ['status' => 'IN_REVIEW', 'position' => 0])->assertOk();
        $this->postJson(self::BASE.'/'.$d->id.'/move', ['status' => 'DONE', 'position' => 1])->assertOk();
        $response = $this->postJson(self::BASE.'/'.$d->id.'/move', ['status' => 'DONE', 'position' => 1])->assertOk();
        $this->assertSame(['TODO' => ['C'], 'IN_REVIEW' => ['B'], 'DONE' => ['A', 'D']], $this->board());
        // The move response is the authoritative whole board, in column order.
        $this->assertSame(['C', 'B', 'A', 'D'], array_column($response->json('data'), 'title'));
        $this->assertSame(4, InternalKanbanTask::count());
        $this->assertSame(['A', 'desc A'], [$a->fresh()->title, $a->fresh()->description]);
        // Deleting compacts the column.
        $this->deleteJson(self::BASE.'/'.$a->id)->assertNoContent();
        $this->assertSame([0], $this->positions()['DONE']);
        // Persisted: a new request sees the same order.
        $this->assertSame(['TODO' => ['C'], 'IN_REVIEW' => ['B'], 'DONE' => ['D']], $this->board());
    }

    public function test_listing_order_is_deterministic_even_with_duplicate_positions(): void
    {
        $this->enable();
        $this->actingAs($this->internalUser(), 'web');
        $this->task('second', 'DONE', 0);
        $this->task('third-same-position', 'DONE', 0);
        $this->task('first', 'TODO', 5);
        $this->assertSame(['first', 'second', 'third-same-position'], array_column($this->getJson(self::BASE)->json('data'), 'title'));
    }

    public function test_failed_move_rolls_back_all_position_updates(): void
    {
        $this->enable();
        $this->actingAs($this->internalUser(), 'web');
        $this->task('A', 'TODO', 0);
        $this->task('B', 'TODO', 1);
        $c = $this->task('C', 'TODO', 2);
        $before = InternalKanbanTask::query()->orderBy('id')->get(['id', 'status', 'position', 'updated_at'])->toArray();

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with(strtolower($query->sql), 'update `internal_kanban_tasks`') && ++$writes === 2) {
                throw new RuntimeException('simulated failure after the first write');
            }
        });
        $this->postJson(self::BASE.'/'.$c->id.'/move', ['status' => 'TODO', 'position' => 0])->assertStatus(500);
        $this->assertGreaterThanOrEqual(2, $writes);
        $this->assertSame($before, InternalKanbanTask::query()->orderBy('id')->get(['id', 'status', 'position', 'updated_at'])->toArray());
    }

    public function test_csrf_is_still_required_for_every_write(): void
    {
        $this->enable();
        $this->app->bind(ValidateCsrfToken::class, KanbanStrictCsrf::class);
        $task = $this->task('A');
        $this->actingAs($this->internalUser(), 'web')->withHeader('Origin', 'http://localhost:3000');
        $this->postJson(self::BASE, ['title' => 'x'])->assertStatus(419)->assertJsonPath('code', 'CSRF_EXPIRED');
        $this->patchJson(self::BASE.'/'.$task->id, ['title' => 'x'])->assertStatus(419);
        $this->deleteJson(self::BASE.'/'.$task->id)->assertStatus(419);
        $this->postJson(self::BASE.'/'.$task->id.'/move', ['status' => 'DONE', 'position' => 0])->assertStatus(419);
        $this->assertSame('A', $task->fresh()->title);

        $token = 'synthetic-csrf-token';
        $this->withSession(['_token' => $token])->withHeader('X-CSRF-TOKEN', $token);
        $this->postJson(self::BASE, ['title' => 'with token'])->assertCreated();
    }

    public function test_table_is_global_innodb_and_has_no_organization_column(): void
    {
        $this->assertFalse(Schema::hasColumn('internal_kanban_tasks', 'organization_id'));
        $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['internal_kanban_tasks']);
        $this->assertSame('InnoDB', $engine->engine);
    }
}
