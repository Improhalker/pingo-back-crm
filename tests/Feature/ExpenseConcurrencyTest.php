<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

/**
 * Real MySQL/InnoDB locking with a second connection. Data is committed (truncated after each test) so the
 * other connection can see it; SQLite or a single wrapped transaction could not show this behaviour.
 */
class ExpenseConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use FinanceFixtures;

    private array $expense;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mysql_concurrent' => config('database.connections.mysql')]);
        $this->setUpCompanies();
        $category = $this->category($this->orgA, 'EXPENSE', 'Combustível');
        $this->expense = $this->as($this->adminA, $this->orgA)
            ->postJson('/api/v1/expenses', ['category_id' => (int) $category, 'description' => 'Despesa sintética', 'total_amount' => '300.00', 'due_date' => now('America/Sao_Paulo')->toDateString(), 'expected_method' => 'PIX'], $this->key())
            ->assertCreated()->json('data');
    }

    protected function tearDown(): void
    {
        DB::connection('mysql_concurrent')->disconnect();
        // DatabaseTruncation cleans before each test; committed rows must not leak into later test classes.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['payments', 'payables', 'expenses', 'receipts', 'receivables', 'revenues', 'idempotency_keys', 'audit_logs', 'categories', 'customers', 'organization_user', 'platform_administrators', 'sessions', 'users', 'organizations'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    private function paymentPayload(string $amount, string $at): array
    {
        return ['amount' => $amount, 'method' => 'PIX', 'paid_at' => $at];
    }

    public function test_deletion_rechecks_a_settlement_committed_by_another_connection(): void
    {
        $other = DB::connection('mysql_concurrent');
        $raced = false;
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$raced, $other) {
            if ($raced || $query->connectionName !== 'mysql' || ! str_contains($query->sql, 'from `idempotency_keys`')) {
                return;
            }
            $raced = true;
            $other->transaction(function () use ($other) {
                $other->select('SELECT id FROM payables WHERE id = ? FOR UPDATE', [$this->expense['payable']['id']]);
                $other->table('payments')->insert([
                    'organization_id' => $this->orgA->id, 'payable_id' => $this->expense['payable']['id'],
                    'amount' => '100.00', 'method' => 'PIX', 'paid_at' => now('UTC'),
                    'created_by' => $this->operatorA->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            });
        });

        $this->as($this->adminA, $this->orgA)->deleteJson('/api/v1/expenses/'.$this->expense['id'], ['expected_version' => 1], $this->key())
            ->assertUnprocessable()->assertJsonValidationErrors('entry');
        $this->assertTrue($raced);
        $this->assertNull(DB::table('expenses')->where('id', $this->expense['id'])->value('deleted_at'));
        $this->assertSame('100.00', (string) DB::table('payments')->sum('amount'));
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_correction_waits_for_the_same_lock_as_a_settlement(): void
    {
        $other = DB::connection('mysql_concurrent');
        $other->beginTransaction();
        $other->select('SELECT id FROM payables WHERE id = ? FOR UPDATE', [$this->expense['payable']['id']]);
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $payload = [
            'expected_version' => 1, 'category_id' => (int) $this->expense['category']['id'],
            'description' => 'Despesa corrigida', 'total_amount' => '350.00',
            'due_date' => $this->expense['payable']['due_date'], 'expected_method' => 'CASH',
        ];
        $headers = $this->key();
        $this->as($this->adminA, $this->orgA)->patchJson('/api/v1/expenses/'.$this->expense['id'], $payload, $headers)->assertStatus(500);
        $this->assertSame('300.00', DB::table('expenses')->value('total_amount'));
        $this->assertSame(1, DB::table('expenses')->value('version'));
        $this->assertSame(1, DB::table('idempotency_keys')->count());
        $other->rollBack();
        $this->patchJson('/api/v1/expenses/'.$this->expense['id'], $payload, $headers)->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_a_different_key_rechecks_a_payment_committed_by_another_connection(): void
    {
        $payableId = (int) $this->expense['payable']['id'];
        $other = DB::connection('mysql_concurrent');
        $raced = false;
        // Interleave the second writer after the retry lookup and before the caller takes the row lock.
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$raced, $other, $payableId) {
            if ($raced || $query->connectionName !== 'mysql' || ! str_contains($query->sql, 'from `idempotency_keys`')) {
                return;
            }
            $raced = true;
            $other->transaction(function () use ($other, $payableId) {
                $other->select('SELECT id FROM payables WHERE id = ? FOR UPDATE', [$payableId]);
                $other->table('payments')->insert([
                    'organization_id' => $this->orgA->id, 'payable_id' => $payableId,
                    'amount' => '250.00', 'method' => 'CASH', 'paid_at' => now('UTC'),
                    'created_by' => $this->operatorA->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
                ]);
            });
        });

        $this->as($this->adminA, $this->orgA)->postJson("/api/v1/payables/{$payableId}/payments",
            $this->paymentPayload('100', now()->subMinute()->toIso8601String()), $this->key())
            ->assertUnprocessable()->assertJsonPath('errors.amount.0', 'O valor excede o restante a pagar de R$ 50,00.');
        $this->assertTrue($raced);
        $this->assertSame('250.00', (string) DB::table('payments')->sum('amount'));
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->count(), 'the rejected payment claim is rolled back');
    }

    public function test_a_payment_waits_for_the_obligation_lock_held_by_another_transaction(): void
    {
        $payableId = (int) $this->expense['payable']['id'];
        $other = DB::connection('mysql_concurrent');
        $other->beginTransaction();
        $other->select('SELECT id FROM payables WHERE id = ? FOR UPDATE', [$payableId]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $at = now()->subMinute()->utc()->format('Y-m-d\TH:i:sP');
        $this->as($this->adminA, $this->orgA)
            ->postJson("/api/v1/payables/{$payableId}/payments", $this->paymentPayload('100', $at), $this->key())
            ->assertStatus(500); // lock wait timeout: the request could not settle without the obligation lock
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->count(), 'the failed claim was rolled back');

        $other->rollBack();
        $this->as($this->adminA, $this->orgA)
            ->postJson("/api/v1/payables/{$payableId}/payments", $this->paymentPayload('100', $at), $this->key())
            ->assertCreated()->assertJsonPath('data.remaining_amount', '200.00');
    }

    public function test_a_concurrent_request_with_the_same_key_does_not_settle_twice(): void
    {
        $payableId = (int) $this->expense['payable']['id'];
        $at = now()->subMinute()->utc()->format('Y-m-d\TH:i:sP');
        $headers = $this->key();
        $canonical = ['payable_id' => $payableId, 'amount' => '100.00', 'method' => 'PIX', 'paid_at' => CarbonImmutable::parse($at)->utc()->format('Y-m-d\TH:i:s.uP')];
        $hash = hash('sha256', json_encode(['payment.create', $canonical], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $other = DB::connection('mysql_concurrent');
        $raced = false;

        // Right after this request checks for a stored result, another request with the same key commits
        // its payment and result: this request then hits the unique claim and must replay, not insert.
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$raced, $other, $payableId, $headers, $hash) {
            if ($raced || $query->connectionName !== 'mysql' || ! str_contains($query->sql, 'from `idempotency_keys`')) {
                return;
            }
            $raced = true;
            $other->transaction(function () use ($other, $payableId, $headers, $hash) {
                $other->table('payments')->insert(['organization_id' => $this->orgA->id, 'payable_id' => $payableId, 'amount' => '100.00', 'method' => 'PIX', 'paid_at' => now('UTC'), 'created_by' => $this->adminA->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                $other->table('idempotency_keys')->insert(['organization_id' => $this->orgA->id, 'user_id' => $this->adminA->id, 'operation' => 'payment.create', 'idempotency_key' => $headers['Idempotency-Key'], 'request_hash' => $hash, 'response_status' => 201, 'response_body' => json_encode(['data' => ['from' => 'concurrent request']]), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            });
        });

        $this->as($this->adminA, $this->orgA)
            ->postJson("/api/v1/payables/{$payableId}/payments", $this->paymentPayload('100', $at), $headers)
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.from', 'concurrent request');
        $this->assertTrue($raced);
        $this->assertSame(1, DB::table('payments')->count());
    }
}
