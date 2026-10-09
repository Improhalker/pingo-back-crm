<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    private string $categoryA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCompanies();
        $this->categoryA = $this->category($this->orgA, 'EXPENSE', 'Combustível');
    }

    private function payload(array $overrides = []): array
    {
        return [
            'category_id' => (int) $this->categoryA,
            'description' => 'Abastecimento do veículo',
            'total_amount' => '300.00',
            'due_date' => now('America/Sao_Paulo')->toDateString(),
            'expected_method' => 'PIX',
            'notes' => 'Observação sintética',
            ...$overrides,
        ];
    }

    private function createExpense(array $overrides = [], ?User $user = null): array
    {
        return $this->as($user ?? $this->adminA, $this->orgA)
            ->postJson('/api/v1/expenses', $this->payload($overrides), $this->key())->assertCreated()->json('data');
    }

    private function pay(array $expense, string $amount, array $headers = [], string $method = 'PIX', ?string $at = null, ?User $user = null)
    {
        return $this->as($user ?? $this->adminA, $this->orgA)->postJson("/api/v1/payables/{$expense['payable']['id']}/payments", [
            'amount' => $amount, 'method' => $method, 'paid_at' => $at ?? now()->subMinute()->utc()->format('Y-m-d\TH:i:sP'),
        ], $headers ?: $this->key());
    }

    public function test_card_acceptance_partial_payment_retry_overpayment_and_reload(): void
    {
        $expense = $this->createExpense();
        $this->assertSame('0.00', $expense['paid_amount']);
        $this->assertSame('300.00', $expense['remaining_amount']);
        $this->assertSame('PENDING', $expense['status']);
        $this->assertSame([], $expense['payments']);
        $this->assertDatabaseCount('payments', 0);

        $headers = $this->key();
        $at = now()->subMinute()->utc()->format('Y-m-d\TH:i:sP');
        $first = $this->pay($expense, '100', $headers, 'PIX', $at)->assertCreated()
            ->assertJsonPath('data.total_amount', '300.00')->assertJsonPath('data.paid_amount', '100.00')
            ->assertJsonPath('data.remaining_amount', '200.00')->assertJsonPath('data.status', 'PENDING')
            ->assertJsonCount(1, 'data.payments');
        $this->pay($expense, '201')->assertUnprocessable()->assertJsonPath('errors.amount.0', 'O valor excede o restante a pagar de R$ 200,00.');
        $again = $this->pay($expense, '100.00', $headers, 'PIX', $at)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data'), $again->json('data'));
        $this->pay($expense, '99', $headers, 'PIX', $at)->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->as($this->adminA, $this->orgA)->getJson("/api/v1/expenses/{$expense['id']}")->assertOk()
            ->assertJsonPath('data.paid_amount', '100.00')->assertJsonPath('data.remaining_amount', '200.00')
            ->assertJsonPath('data.payments.0.method', 'PIX');
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['organization_id' => $this->orgA->id, 'amount' => '100.00', 'created_by' => $this->adminA->id]);
    }

    public function test_creation_is_idempotent_and_never_accepts_a_paid_status_from_client(): void
    {
        $api = $this->as($this->adminA, $this->orgA);
        $api->postJson('/api/v1/expenses', $this->payload())->assertJsonValidationErrors('idempotency_key');
        $headers = $this->key();
        $id = $api->postJson('/api/v1/expenses', $this->payload(), $headers)->assertCreated()->json('data.id');
        $api->postJson('/api/v1/expenses', $this->payload(['total_amount' => '300']), $headers)
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $id);
        $api->postJson('/api/v1/expenses', $this->payload(['total_amount' => '301']), $headers)->assertStatus(409);
        foreach (['status' => 'PAID', 'paid_amount' => '300.00', 'organization_id' => $this->orgB->id, 'created_by' => $this->operatorA->id] as $field => $value) {
            $api->postJson('/api/v1/expenses', $this->payload([$field => $value]), $this->key())->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('payables', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_methods_instants_and_partial_payments_are_preserved_until_settled(): void
    {
        $expense = $this->createExpense();
        $this->pay($expense, '100.25', [], 'PIX', '2026-10-01T09:15:00-03:00')->assertCreated();
        $this->pay($expense, '99.75', [], 'CASH', '2026-10-02T18:40:00-03:00')->assertCreated()
            ->assertJsonPath('data.paid_amount', '200.00')->assertJsonPath('data.remaining_amount', '100.00')
            ->assertJsonPath('data.payments.0.method', 'PIX')->assertJsonPath('data.payments.0.amount', '100.25')
            ->assertJsonPath('data.payments.0.paid_at', '2026-10-01T12:15:00.000+00:00')
            ->assertJsonPath('data.payments.1.method', 'CASH');
        $this->pay($expense, '100', [], 'CARD')->assertCreated()->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.remaining_amount', '0.00');
        $this->pay($expense, '0.01')->assertUnprocessable()->assertJsonPath('errors.amount.0', 'Esta despesa já está quitada.');
        $this->assertDatabaseCount('payments', 3);
    }

    public function test_money_is_exact_positive_limited_and_never_rounded(): void
    {
        $api = $this->as($this->adminA, $this->orgA);
        foreach (['100.001', '-5.00', '0', '1000000000.00', '1.000,00', '1e3', '01.00', 300.1] as $amount) {
            $api->postJson('/api/v1/expenses', $this->payload(['total_amount' => $amount]), $this->key())->assertJsonValidationErrors('total_amount');
        }
        $this->assertSame('999999999.99', $this->createExpense(['total_amount' => '999999999.99'])['total_amount']);
        $expense = $this->createExpense(['total_amount' => '0.30']);
        foreach (['0', '-1', '0.001', '1e2', 0.1] as $amount) {
            $api->postJson("/api/v1/payables/{$expense['payable']['id']}/payments", ['amount' => $amount, 'method' => 'PIX', 'paid_at' => now()->subMinute()->toIso8601String()], $this->key())->assertJsonValidationErrors('amount');
        }
        $this->pay($expense, '0.10')->assertCreated();
        $this->pay($expense, '0.20')->assertCreated()->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.remaining_amount', '0.00');
    }

    public function test_today_yesterday_partial_and_settled_status_use_the_company_day(): void
    {
        CarbonImmutable::setTestNow('2026-10-07T01:30:00Z');
        try {
            $today = $this->createExpense(['due_date' => '2026-10-06']);
            $late = $this->createExpense(['due_date' => '2026-10-05']);
            $this->assertSame('PENDING', $today['status']);
            $this->assertSame('OVERDUE', $late['status']);
            $this->pay($late, '100', [], 'PIX', '2026-10-06T20:00:00-03:00')->assertJsonPath('data.status', 'OVERDUE');
            $api = $this->as($this->adminA, $this->orgA);
            $api->getJson('/api/v1/expenses?status=pending')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $today['id']);
            $api->getJson('/api/v1/expenses?status=overdue')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $late['id']);
            $this->pay($late, '200', [], 'PIX', '2026-10-06T20:00:00-03:00')->assertJsonPath('data.status', 'PAID');
            $api->getJson('/api/v1/expenses?status=paid')->assertJsonPath('meta.total', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_search_pagination_and_inclusive_due_period_do_not_multiply_totals(): void
    {
        $first = $this->createExpense(['due_date' => '2026-10-05']);
        $this->createExpense(['description' => 'Troca de pneus', 'due_date' => '2026-10-06']);
        $this->createExpense(['description' => 'Revisão do carro', 'due_date' => '2026-10-07']);
        $this->pay($first, '10')->assertCreated();
        $this->pay($first, '20')->assertCreated();
        $api = $this->as($this->adminA, $this->orgA);
        $api->getJson('/api/v1/expenses?per_page=1')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3)->assertJsonPath('data.0.total_amount', '300.00')->assertJsonPath('data.0.paid_amount', '30.00');
        $api->getJson('/api/v1/expenses?due_from=2026-10-05&due_to=2026-10-06')->assertJsonPath('meta.total', 2);
        $api->getJson('/api/v1/expenses?due_to=2026-10-05')->assertJsonPath('meta.total', 1);
        $api->getJson('/api/v1/expenses?search=pneus')->assertJsonPath('meta.total', 1);
        $api->getJson('/api/v1/expenses?search=Combust')->assertJsonPath('meta.total', 3);
        $api->getJson('/api/v1/expenses?due_from=2026-10-07&due_to=2026-10-05')->assertJsonValidationErrors('due_to');
        $api->getJson('/api/v1/expenses?status=received')->assertJsonValidationErrors('status');
        $api->getJson('/api/v1/expenses?due_from=2026-02-30')->assertJsonValidationErrors('due_from');
    }

    public function test_categories_must_be_active_expense_categories_of_this_company(): void
    {
        $foreign = $this->category($this->orgB, 'EXPENSE', 'Outra empresa');
        $revenueCategory = $this->category($this->orgA, 'REVENUE', 'Aulas');
        $api = $this->as($this->adminA, $this->orgA);
        $api->postJson('/api/v1/expenses', $this->payload(['category_id' => $foreign]), $this->key())->assertJsonPath('errors.category_id.0', 'Escolha uma categoria desta empresa.');
        $api->postJson('/api/v1/expenses', $this->payload(['category_id' => $revenueCategory]), $this->key())->assertJsonPath('errors.category_id.0', 'Escolha uma categoria de despesa.');
        $expense = $this->createExpense();
        $api->postJson("/api/v1/categories/{$this->categoryA}/archive")->assertOk();
        $api->postJson('/api/v1/expenses', $this->payload(), $this->key())->assertJsonValidationErrors('category_id');
        $api->getJson("/api/v1/expenses/{$expense['id']}")->assertJsonPath('data.category.archived', true);
        $this->pay($expense, '100')->assertCreated(); // Archiving the category must not block settling old expenses.
    }

    public function test_company_isolation_roles_master_and_revoked_membership(): void
    {
        $expense = $this->createExpense([], $this->operatorA);
        $this->pay($expense, '100', [], 'CASH', null, $this->operatorA)->assertCreated();
        $this->as($this->operatorA, $this->orgA)->getJson('/api/v1/expenses')->assertOk()->assertJsonPath('meta.total', 1);
        foreach ([$this->adminB, $this->master] as $user) {
            $api = $this->as($user, $user->is($this->adminB) ? $this->orgB : $this->orgA);
            // Other company: not found. Master (no membership): explicit denial (ADR-028).
            $status = $user->is($this->adminB) ? 404 : 403;
            $api->getJson("/api/v1/expenses/{$expense['id']}")->assertStatus($status);
            $api->postJson("/api/v1/payables/{$expense['payable']['id']}/payments", ['amount' => '1', 'method' => 'PIX', 'paid_at' => now()->subMinute()->toIso8601String()], $this->key())->assertStatus($status);
        }
        $this->as($this->adminB, $this->orgB)->getJson('/api/v1/expenses')->assertJsonPath('meta.total', 0);
        $this->operatorA->organizations()->detach($this->orgA);
        $this->as($this->operatorA, $this->orgA)->getJson('/api/v1/expenses')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
    }

    public function test_invalid_civil_dates_and_payment_instants_are_rejected(): void
    {
        $api = $this->as($this->adminA, $this->orgA);
        foreach (['2026-02-30', '1999-12-31', '2101-01-01', '06/10/2026'] as $due) {
            $api->postJson('/api/v1/expenses', $this->payload(['due_date' => $due]), $this->key())->assertJsonValidationErrors('due_date');
        }
        $expense = $this->createExpense();
        foreach (['2026-10-06T10:00:00', '2026-02-30T10:00:00Z', '2026-10-06T25:00:00Z', '2026-10-06T10:00:00+15:00', '1999-12-31T10:00:00Z', now()->addHour()->toIso8601String()] as $instant) {
            $this->pay($expense, '10', [], 'PIX', $instant)->assertJsonValidationErrors('paid_at');
        }
        $this->pay($expense, '10', [], 'BITCOIN')->assertJsonValidationErrors('method');
        $this->assertDatabaseCount('payments', 0);
        $this->pay($expense, '10', [], 'PIX', '2026-10-01T09:15Z')->assertCreated();
    }

    public function test_audits_are_atomic_and_failure_rolls_back_expense_payment_and_key(): void
    {
        $expense = $this->createExpense();
        $this->pay($expense, '100')->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense.created', 'entity_type' => 'Expense', 'entity_id' => $expense['id'], 'organization_id' => $this->orgA->id]);
        $log = AuditLog::query()->where('action', 'payment.created')->firstOrFail();
        $this->assertSame('300.00', $log->previous_data['payable']['remaining_amount']);
        $this->assertSame('200.00', $log->new_data['payable']['remaining_amount']);
        $this->app->bind(AuditTrail::class, fn ($app) => new class($app->make(OrganizationContext::class)) extends AuditTrail
        {
            public function record(User $user, string $action, Model $entity, ?array $before, ?array $after): AuditLog
            {
                throw new RuntimeException('simulated audit failure');
            }
        });
        $this->as($this->adminA, $this->orgA)->postJson('/api/v1/expenses', $this->payload(['description' => 'Não deve existir']), $this->key())->assertStatus(500);
        $this->pay($expense, '50')->assertStatus(500);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('payables', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('idempotency_keys', 2);
    }

    public function test_database_composite_foreign_keys_reject_external_parent_and_preserve_history(): void
    {
        $expense = $this->createExpense();
        $this->pay($expense, '100')->assertCreated();
        foreach ([
            fn () => DB::table('expenses')->where('id', $expense['id'])->update(['organization_id' => $this->orgB->id]),
            fn () => DB::table('payments')->where('payable_id', $expense['payable']['id'])->update(['organization_id' => $this->orgB->id]),
            fn () => DB::table('payables')->where('id', $expense['payable']['id'])->delete(),
            fn () => DB::table('categories')->where('id', $this->categoryA)->delete(),
        ] as $invalidWrite) {
            try {
                $invalidWrite();
                $this->fail('A restrictive foreign key must reject this operation.');
            } catch (QueryException $exception) {
                $this->assertSame('23000', $exception->errorInfo[0]);
            }
        }
        $this->assertDatabaseCount('payments', 1);
    }
}
