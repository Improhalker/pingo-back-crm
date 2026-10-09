<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

/** Committed fixtures and a second MySQL connection exercise a real concurrent write between aggregates. */
class DashboardConsistencyTest extends TestCase
{
    use DatabaseTruncation;
    use FinanceFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::connection('mysql_dashboard_writer')->disconnect();
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['payments', 'payables', 'expenses', 'receipts', 'receivables', 'revenues', 'idempotency_keys', 'audit_logs', 'categories', 'customers', 'organization_user', 'platform_administrators', 'sessions', 'users', 'organizations'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_all_aggregates_share_a_snapshot_and_refresh_sees_the_concurrent_payment(): void
    {
        CarbonImmutable::setTestNow('2026-10-07T12:00:00Z');
        config(['database.connections.mysql_dashboard_writer' => config('database.connections.mysql')]);
        $this->setUpCompanies();
        $category = $this->category($this->orgA, 'EXPENSE', 'Categoria sintética');
        $expense = $this->as($this->adminA, $this->orgA)->postJson('/api/v1/expenses', [
            'category_id' => (int) $category, 'description' => 'Teste de leitura consistente',
            'total_amount' => '300.00', 'due_date' => '2026-10-06', 'expected_method' => 'PIX',
        ], $this->key())->assertCreated()->json('data');
        // The dashboard must remain consistent even when the server/session default is READ COMMITTED.
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $written = false;
        $other = DB::connection('mysql_dashboard_writer');
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$written, $other, $expense) {
            if ($written || $query->connectionName !== 'mysql' || ! str_contains($query->sql, 'SUM(amount) AS total') || ! str_contains($query->sql, 'from `receipts`')) {
                return;
            }
            $written = true;
            $other->table('payments')->insert([
                'organization_id' => $this->orgA->id, 'payable_id' => $expense['payable']['id'],
                'amount' => '100.00', 'method' => 'PIX', 'paid_at' => '2026-10-06 13:00:00',
                'created_by' => $this->adminA->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $api = $this->as($this->adminA, $this->orgA);
        $api->getJson('/api/v1/dashboard?month=2026-10')->assertOk()
            ->assertJsonPath('data.monthly.expenses', '0.00')->assertJsonPath('data.daily.5.expenses', '0.00')
            ->assertJsonPath('data.outstanding.payable', '300.00')->assertJsonPath('data.due.overdue.items.0.remaining_amount', '300.00');
        $this->assertTrue($written);
        $this->assertSame(1, $other->table('payments')->count());
        $this->assertSame('READ-COMMITTED', DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        $api->getJson('/api/v1/dashboard?month=2026-10')->assertOk()
            ->assertJsonPath('data.monthly.expenses', '100.00')->assertJsonPath('data.daily.5.expenses', '100.00')
            ->assertJsonPath('data.outstanding.payable', '200.00')->assertJsonPath('data.due.overdue.items.0.remaining_amount', '200.00');
    }
}
