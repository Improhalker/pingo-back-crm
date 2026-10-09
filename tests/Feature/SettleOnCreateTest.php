<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

/** Card 06: free due date while pending, and "already received/paid in full" in the same submission. */
class SettleOnCreateTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    private string $customerA;

    private string $revenueCategoryA;

    private string $expenseCategoryA;

    protected function setUp(): void
    {
        parent::setUp();
        // 07/10/2026 15:00 in São Paulo, the acceptance scenario of the card.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 15:00:00', 'America/Sao_Paulo'));
        $this->setUpCompanies();
        $this->customerA = $this->customer($this->orgA);
        $this->revenueCategoryA = $this->category($this->orgA);
        $this->expenseCategoryA = $this->category($this->orgA, 'EXPENSE', 'Combustível');
    }

    private function revenue(array $overrides = [], ?User $user = null, ?array $key = null)
    {
        $payload = $this->revenuePayload($this->customerA, $this->revenueCategoryA, ['total_amount' => '300.00', 'sale_date' => '2026-10-07', ...$overrides]);

        return $this->as($user ?? $this->adminA, $this->orgA)->postJson('/api/v1/revenues', array_filter($payload, fn ($v) => $v !== '__omit'), $key ?? $this->key());
    }

    private function expense(array $overrides = [], ?User $user = null, ?array $key = null)
    {
        $payload = [
            'category_id' => (int) $this->expenseCategoryA, 'description' => 'Abastecimento do veículo', 'total_amount' => '300.00',
            'due_date' => '2026-10-20', 'expected_method' => 'BOLETO', 'notes' => null, ...$overrides,
        ];

        return $this->as($user ?? $this->adminA, $this->orgA)->postJson('/api/v1/expenses', array_filter($payload, fn ($v) => $v !== '__omit'), $key ?? $this->key());
    }

    /** A settled entry sends no due date nor expected method. */
    private function settled(string $field, string $at = '2026-10-07T14:30:00-03:00', string $method = 'PIX'): array
    {
        return ['due_date' => '__omit', 'expected_method' => '__omit', 'settlement' => ['method' => $method, $field => $at]];
    }

    public function test_pending_revenue_keeps_a_due_date_chosen_independently_of_the_sale(): void
    {
        $this->revenue(['due_date' => '2026-10-20'])->assertCreated()
            ->assertJsonPath('data.sale_date', '2026-10-07')
            ->assertJsonPath('data.receivable.due_date', '2026-10-20')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.received_amount', '0.00')
            ->assertJsonPath('data.remaining_amount', '300.00');
        $this->assertDatabaseCount('receipts', 0);
        // A past due date alone never settles anything.
        $this->revenue(['sale_date' => '2026-10-01', 'due_date' => '2026-10-02'])->assertCreated()->assertJsonPath('data.status', 'OVERDUE');
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_revenue_received_at_once_saves_sale_obligation_receipt_and_audit_together(): void
    {
        $revenue = $this->revenue($this->settled('received_at'))->assertCreated()
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.received_amount', '300.00')
            ->assertJsonPath('data.remaining_amount', '0.00')
            // Documented rule: the obligation is due on the civil date of the effective receipt.
            ->assertJsonPath('data.receivable.due_date', '2026-10-07')
            ->assertJsonPath('data.receivable.expected_method', 'PIX')
            ->assertJsonPath('data.receipts.0.amount', '300.00')
            ->assertJsonPath('data.receipts.0.method', 'PIX')
            ->assertJsonPath('data.receipts.0.received_at', '2026-10-07T17:30:00.000+00:00')
            ->json('data');
        // Reload shows the same settled state.
        $this->as($this->adminA, $this->orgA)->getJson("/api/v1/revenues/{$revenue['id']}")->assertOk()
            ->assertJsonPath('data.status', 'RECEIVED')->assertJsonPath('data.remaining_amount', '0.00');
        $this->assertSame(['receipt.created', 'revenue.created'], AuditLog::query()->orderBy('action')->pluck('action')->all());
        // Further receipts are refused: nothing remains.
        $this->as($this->adminA, $this->orgA)->postJson("/api/v1/receivables/{$revenue['receivable']['id']}/receipts", ['amount' => '0.01', 'method' => 'PIX', 'received_at' => '2026-10-07T14:40:00-03:00'], $this->key())
            ->assertUnprocessable();
    }

    public function test_expense_paid_at_once_and_pending_expense_with_free_due_date(): void
    {
        $this->expense()->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.payable.due_date', '2026-10-20')->assertJsonPath('data.paid_amount', '0.00');
        $this->assertDatabaseCount('payments', 0);

        $expense = $this->expense($this->settled('paid_at'))->assertCreated()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.paid_amount', '300.00')
            ->assertJsonPath('data.remaining_amount', '0.00')
            ->assertJsonPath('data.payable.due_date', '2026-10-07')
            ->assertJsonPath('data.payments.0.method', 'PIX')
            ->json('data');
        $this->as($this->adminA, $this->orgA)->getJson("/api/v1/expenses/{$expense['id']}")->assertOk()->assertJsonPath('data.status', 'PAID');
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.created')->count());
    }

    public function test_dashboard_counts_only_settled_entries_by_their_effective_date(): void
    {
        $this->revenue(['due_date' => '2026-10-20'])->assertCreated();
        $this->expense()->assertCreated();
        $this->revenue($this->settled('received_at', '2026-10-07T10:00:00-03:00'))->assertCreated();
        $this->expense($this->settled('paid_at', '2026-09-30T23:30:00-03:00', 'CASH'))->assertCreated();

        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-10')->assertOk()
            ->assertJsonPath('data.monthly', ['income' => '300.00', 'expenses' => '0.00', 'result' => '300.00'])
            ->assertJsonPath('data.outstanding.receivable', '300.00')
            ->assertJsonPath('data.outstanding.payable', '300.00');
        // The payment made on 30/09 (local) belongs to September, not to the creation day.
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-09')->assertOk()
            ->assertJsonPath('data.monthly.expenses', '300.00');
    }

    public function test_retry_with_the_same_key_does_not_duplicate_entry_or_settlement(): void
    {
        $key = $this->key();
        $first = $this->revenue($this->settled('received_at'), key: $key)->assertCreated()->json('data.id');
        $this->revenue($this->settled('received_at'), key: $key)->assertCreated()->assertJsonPath('data.id', $first);
        // Same key with a different settlement is a conflict, never a second write.
        $this->revenue($this->settled('received_at', method: 'CASH'), key: $key)->assertStatus(409);
        $this->assertDatabaseCount('revenues', 1);
        $this->assertDatabaseCount('receipts', 1);

        $paymentKey = $this->key();
        $this->expense($this->settled('paid_at'), key: $paymentKey)->assertCreated();
        $this->expense($this->settled('paid_at'), key: $paymentKey)->assertCreated();
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_failure_in_the_settlement_rolls_back_the_whole_entry(): void
    {
        // The settlement audit is the last write: failing there must undo sale, obligation, receipt and key.
        $this->app->bind(AuditTrail::class, fn ($app) => new class($app->make(OrganizationContext::class)) extends AuditTrail
        {
            public function record(User $user, string $action, Model $entity, ?array $before, ?array $after): AuditLog
            {
                if (in_array($action, ['receipt.created', 'payment.created'], true)) {
                    throw new RuntimeException('simulated settlement audit failure');
                }

                return parent::record($user, $action, $entity, $before, $after);
            }
        });
        $this->revenue($this->settled('received_at'))->assertStatus(500);
        $this->expense($this->settled('paid_at'))->assertStatus(500);
        foreach (['revenues', 'receivables', 'receipts', 'expenses', 'payables', 'payments', 'idempotency_keys', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_settlement_validation_is_explicit_and_strict(): void
    {
        // Date in the future, before the sale, missing method, unknown fields, partial amount and mixed modes.
        $this->revenue($this->settled('received_at', '2026-10-08T10:00:00-03:00'))->assertUnprocessable()->assertJsonValidationErrors('settlement.received_at');
        $this->revenue(['sale_date' => '2026-10-07', ...$this->settled('received_at', '2026-10-06T10:00:00-03:00')])
            ->assertUnprocessable()->assertJsonPath('errors', ['settlement.received_at' => ['O recebimento não pode ser anterior à data da venda.']]);
        $this->revenue(['due_date' => '__omit', 'expected_method' => '__omit', 'settlement' => ['received_at' => '2026-10-07T10:00:00-03:00']])
            ->assertUnprocessable()->assertJsonValidationErrors('settlement.method');
        $this->revenue(['due_date' => '__omit', 'expected_method' => '__omit', 'settlement' => ['method' => 'PIX', 'received_at' => '2026-10-07T10:00:00-03:00', 'amount' => '100.00']])
            ->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $this->revenue(['settlement' => ['method' => 'PIX', 'received_at' => '2026-10-07T10:00:00-03:00']])
            ->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'expected_method']);
        $this->expense(['due_date' => '__omit', 'expected_method' => '__omit', 'settlement' => ['method' => 'NOPE', 'paid_at' => 'ontem']])
            ->assertUnprocessable()->assertJsonValidationErrors(['settlement.method', 'settlement.paid_at']);
        // Without a settlement the due date stays required.
        $this->revenue(['due_date' => '__omit'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        foreach (['revenues', 'receipts', 'expenses', 'payments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_isolation_roles_and_master_apply_to_settled_creation(): void
    {
        // OPERATOR creates and settles; another company's category is refused; the master has no context.
        $this->revenue($this->settled('received_at'), $this->operatorA)->assertCreated()->assertJsonPath('data.status', 'RECEIVED');
        $categoryB = $this->category($this->orgB, 'EXPENSE', 'Aluguel');
        $this->expense(['category_id' => (int) $categoryB, ...$this->settled('paid_at')])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->as($this->master, $this->orgA)->postJson('/api/v1/expenses', [
            'category_id' => (int) $this->expenseCategoryA, 'description' => 'X', 'total_amount' => '1.00', 'settlement' => ['method' => 'PIX', 'paid_at' => '2026-10-07T10:00:00-03:00'],
        ], $this->key())->assertForbidden();
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('receipts')->where('organization_id', $this->orgB->id)->count());
    }
}
