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
class RevenueConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use FinanceFixtures;

    private array $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.mysql_concurrent' => config('database.connections.mysql')]);
        $this->setUpCompanies();
        $customer = $this->customer($this->orgA);
        $category = $this->category($this->orgA);
        $this->revenue = $this->as($this->adminA, $this->orgA)
            ->postJson('/api/v1/revenues', $this->revenuePayload($customer, $category), $this->key())
            ->assertCreated()->json('data');
    }

    protected function tearDown(): void
    {
        DB::connection('mysql_concurrent')->disconnect();
        // DatabaseTruncation cleans before each test; committed rows must not leak into later test classes.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['payments', 'payables', 'expenses', 'receipts', 'idempotency_keys', 'audit_logs', 'receivables', 'revenues', 'categories', 'customers', 'organization_user', 'platform_administrators', 'sessions', 'users', 'organizations'] as $table) {
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    private function receiptPayload(string $amount, string $at): array
    {
        return ['amount' => $amount, 'method' => 'PIX', 'received_at' => $at];
    }

    public function test_a_receipt_waits_for_the_obligation_lock_held_by_another_transaction(): void
    {
        $receivableId = (int) $this->revenue['receivable']['id'];
        $other = DB::connection('mysql_concurrent');
        $other->beginTransaction();
        $other->select('SELECT id FROM receivables WHERE id = ? FOR UPDATE', [$receivableId]);

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $at = now()->subMinute()->utc()->format('Y-m-d\TH:i:sP');
        $this->as($this->adminA, $this->orgA)
            ->postJson("/api/v1/receivables/{$receivableId}/receipts", $this->receiptPayload('400', $at), $this->key())
            ->assertStatus(500); // lock wait timeout: the request could not settle without the obligation lock
        $this->assertSame(0, DB::table('receipts')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->count(), 'the failed claim was rolled back');

        $other->rollBack();
        $this->as($this->adminA, $this->orgA)
            ->postJson("/api/v1/receivables/{$receivableId}/receipts", $this->receiptPayload('400', $at), $this->key())
            ->assertCreated()->assertJsonPath('data.remaining_amount', '600.00');
    }

    public function test_a_concurrent_request_with_the_same_key_does_not_settle_twice(): void
    {
        $receivableId = (int) $this->revenue['receivable']['id'];
        $at = now()->subMinute()->utc()->format('Y-m-d\TH:i:sP');
        $headers = $this->key();
        $canonical = ['receivable_id' => $receivableId, 'amount' => '400.00', 'method' => 'PIX', 'received_at' => CarbonImmutable::parse($at)->utc()->format('Y-m-d\TH:i:s.uP')];
        $hash = hash('sha256', json_encode(['receipt.create', $canonical], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $other = DB::connection('mysql_concurrent');
        $raced = false;

        // Right after this request checks for a stored result, another request with the same key commits
        // its receipt and result: this request then hits the unique claim and must replay, not insert.
        Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$raced, $other, $receivableId, $headers, $hash) {
            if ($raced || $query->connectionName !== 'mysql' || ! str_contains($query->sql, 'from `idempotency_keys`')) {
                return;
            }
            $raced = true;
            $other->transaction(function () use ($other, $receivableId, $headers, $hash) {
                $other->table('receipts')->insert(['organization_id' => $this->orgA->id, 'receivable_id' => $receivableId, 'amount' => '400.00', 'method' => 'PIX', 'received_at' => now('UTC'), 'created_by' => $this->adminA->id, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
                $other->table('idempotency_keys')->insert(['organization_id' => $this->orgA->id, 'user_id' => $this->adminA->id, 'operation' => 'receipt.create', 'idempotency_key' => $headers['Idempotency-Key'], 'request_hash' => $hash, 'response_status' => 201, 'response_body' => json_encode(['data' => ['from' => 'concurrent request']]), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            });
        });

        $this->as($this->adminA, $this->orgA)
            ->postJson("/api/v1/receivables/{$receivableId}/receipts", $this->receiptPayload('400', $at), $headers)
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.from', 'concurrent request');
        $this->assertTrue($raced);
        $this->assertSame(1, DB::table('receipts')->count());
    }
}
