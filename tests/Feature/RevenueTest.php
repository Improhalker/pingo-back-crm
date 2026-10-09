<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

/** Topic 02: revenues, their single obligation and partial/total receipts. */
class RevenueTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    private string $customerA;

    private string $categoryA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCompanies();
        $this->customerA = $this->customer($this->orgA);
        $this->categoryA = $this->category($this->orgA);
    }

    private function createRevenue(array $overrides = [], ?User $user = null): array
    {
        return $this->as($user ?? $this->adminA, $this->orgA)
            ->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, $overrides), $this->key())
            ->assertCreated()->json('data');
    }

    private function receipt(array $revenue, string $amount, array $headers = [], string $method = 'PIX', ?string $at = null, ?User $user = null)
    {
        return $this->as($user ?? $this->adminA, $this->orgA)->postJson(
            "/api/v1/receivables/{$revenue['receivable']['id']}/receipts",
            ['amount' => $amount, 'method' => $method, 'received_at' => $at ?? now()->subMinute()->utc()->format('Y-m-d\TH:i:sP')],
            $headers ?: $this->key(),
        );
    }

    public function test_acceptance_sale_1000_partial_400_rejects_700_retry_and_other_company(): void
    {
        $revenue = $this->createRevenue(['total_amount' => '1000']);
        $this->assertSame('1000.00', $revenue['total_amount']);

        $key = $this->key();
        $at = now()->subMinute()->utc()->format('Y-m-d\TH:i:sP');
        $first = $this->receipt($revenue, '400', $key, 'PIX', $at)->assertCreated();
        $first->assertJsonPath('data.total_amount', '1000.00')
            ->assertJsonPath('data.received_amount', '400.00')
            ->assertJsonPath('data.remaining_amount', '600.00')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonCount(1, 'data.receipts');

        $this->receipt($revenue, '700')->assertUnprocessable()->assertJsonValidationErrors('amount')
            ->assertJsonPath('errors.amount.0', 'O valor excede o restante a receber de R$ 600,00.');

        // Retrying the R$400 with the same key and payload replays the stored result; nothing is duplicated.
        $this->receipt($revenue, '400', $key, 'PIX', $at)->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.remaining_amount', '600.00');
        $this->assertDatabaseCount('receipts', 1);

        $intruder = $this->as($this->adminB, $this->orgB);
        $intruder->getJson("/api/v1/revenues/{$revenue['id']}")->assertNotFound();
        $intruder->postJson("/api/v1/receivables/{$revenue['receivable']['id']}/receipts", ['amount' => '100', 'method' => 'PIX', 'received_at' => now()->subMinute()->toIso8601String()], $this->key())->assertNotFound();
        $intruder->getJson('/api/v1/revenues')->assertOk()->assertJsonPath('meta.total', 0);

        $this->as($this->adminA, $this->orgA)->getJson("/api/v1/revenues/{$revenue['id']}")
            ->assertJsonPath('data.received_amount', '400.00')->assertJsonPath('data.remaining_amount', '600.00');
        $this->assertDatabaseCount('receipts', 1);
    }

    public function test_retry_with_the_same_key_and_payload_replays_without_duplicating(): void
    {
        $revenue = $this->createRevenue();
        $key = $this->key();
        $at = now()->subMinutes(2)->utc()->format('Y-m-d\TH:i:sP');
        $first = $this->receipt($revenue, '400', $key, 'PIX', $at)->assertCreated();
        $again = $this->receipt($revenue, '400.00', $key, 'PIX', $at)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data'), $again->json('data'));
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('idempotency_keys', 2); // the revenue creation and this receipt

        // The same key with another payload is a conflict, never a second receipt.
        $this->receipt($revenue, '300', $key, 'PIX', $at)->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertDatabaseCount('receipts', 1);
    }

    public function test_revenue_creation_is_idempotent_and_requires_a_key(): void
    {
        $payload = $this->revenuePayload($this->customerA, $this->categoryA);
        $api = $this->as($this->adminA, $this->orgA);
        $api->postJson('/api/v1/revenues', $payload)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $key = $this->key();
        $id = $api->postJson('/api/v1/revenues', $payload, $key)->assertCreated()->json('data.id');
        $api->postJson('/api/v1/revenues', $payload, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $id);
        $api->postJson('/api/v1/revenues', [...$payload, 'total_amount' => '999.00'], $key)->assertStatus(409);
        $this->assertDatabaseCount('revenues', 1);
        $this->assertDatabaseCount('receivables', 1);
    }

    public function test_a_sale_is_not_cash_and_keeps_one_obligation(): void
    {
        $revenue = $this->createRevenue(['total_amount' => '250.50', 'expected_method' => 'CARD']);
        $this->assertSame('0.00', $revenue['received_amount']);
        $this->assertSame('250.50', $revenue['remaining_amount']);
        $this->assertSame('250.50', $revenue['receivable']['amount']);
        $this->assertSame('CARD', $revenue['receivable']['expected_method']);
        $this->assertSame([], $revenue['receipts']);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseHas('revenues', ['id' => $revenue['id'], 'organization_id' => $this->orgA->id, 'total_amount' => '250.50', 'created_by' => $this->adminA->id]);
    }

    public function test_partial_receipts_keep_each_amount_method_and_instant_until_fully_received(): void
    {
        $revenue = $this->createRevenue(['total_amount' => '1000.00']);
        $this->receipt($revenue, '300.25', [], 'PIX', '2026-10-01T09:15:00-03:00')->assertCreated();
        $this->receipt($revenue, '199.75', [], 'CASH', '2026-10-02T18:40:00-03:00')->assertCreated()
            ->assertJsonPath('data.received_amount', '500.00')->assertJsonPath('data.remaining_amount', '500.00')
            ->assertJsonPath('data.receipts.0.method', 'PIX')->assertJsonPath('data.receipts.0.amount', '300.25')
            ->assertJsonPath('data.receipts.0.received_at', '2026-10-01T12:15:00.000+00:00')
            ->assertJsonPath('data.receipts.1.method', 'CASH');
        $this->receipt($revenue, '500')->assertCreated()->assertJsonPath('data.status', 'RECEIVED')->assertJsonPath('data.remaining_amount', '0.00');
        $this->receipt($revenue, '0.01')->assertUnprocessable()->assertJsonPath('errors.amount.0', 'Este recebimento já está quitado.');
        $this->assertDatabaseCount('receipts', 3);
    }

    public function test_money_is_exact_positive_limited_and_never_rounded(): void
    {
        $api = $this->as($this->adminA, $this->orgA);
        foreach (['1000.001', '-5.00', '0', '0.00', '1000000000.00', '1.000,00', '1e3', ' ', '01.00'] as $amount) {
            $api->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, ['total_amount' => $amount]), $this->key())
                ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
        }
        // JSON numbers would become floats in PHP: only decimal strings are accepted.
        $api->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, ['total_amount' => 1000.1]), $this->key())
            ->assertJsonValidationErrors('total_amount');
        $this->assertDatabaseCount('revenues', 0);
        $this->assertSame('999999999.99', $this->createRevenue(['total_amount' => '999999999.99'])['total_amount']);
        $revenue = $this->createRevenue(['total_amount' => '0.30']);
        $this->receipt($revenue, '0.10')->assertCreated();
        $this->receipt($revenue, '0.20')->assertCreated()->assertJsonPath('data.remaining_amount', '0.00')->assertJsonPath('data.status', 'RECEIVED');
    }

    public function test_dates_are_validated_with_civil_dates_and_effective_instants(): void
    {
        $api = $this->as($this->adminA, $this->orgA);
        $today = CarbonImmutable::now('America/Sao_Paulo');
        $api->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, ['sale_date' => $today->addDay()->toDateString()]), $this->key())
            ->assertJsonValidationErrors('sale_date');
        $api->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, ['sale_date' => $today->toDateString(), 'due_date' => $today->subDay()->toDateString()]), $this->key())
            ->assertJsonValidationErrors('due_date');
        $api->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, ['sale_date' => '06/10/2026']), $this->key())
            ->assertJsonValidationErrors('sale_date');
        $revenue = $this->createRevenue();
        $this->receipt($revenue, '10', [], 'PIX', now()->addHour()->toIso8601String())->assertJsonValidationErrors('received_at');
        $this->receipt($revenue, '10', [], 'PIX', '2026-10-06T10:00:00')->assertJsonValidationErrors('received_at');
        $this->receipt($revenue, '10', [], 'BITCOIN')->assertJsonValidationErrors('method');
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_status_derives_from_values_and_the_organization_day(): void
    {
        // 01:30 UTC on 07/10 is still 22:30 on 06/10 in São Paulo: a due date of 06/10 is due today, not late.
        CarbonImmutable::setTestNow('2026-10-07T01:30:00Z');
        try {
            $dueToday = $this->createRevenue(['sale_date' => '2026-10-01', 'due_date' => '2026-10-06']);
            $this->assertSame('PENDING', $dueToday['status']);
            $late = $this->createRevenue(['sale_date' => '2026-10-01', 'due_date' => '2026-10-05']);
            $this->assertSame('OVERDUE', $late['status']);
            $api = $this->as($this->adminA, $this->orgA);
            $api->getJson('/api/v1/revenues?status=overdue')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $late['id']);
            $api->getJson('/api/v1/revenues?status=pending')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $dueToday['id']);
            $this->receipt($late, '1000', [], 'PIX', '2026-10-06T20:00:00-03:00')->assertCreated()->assertJsonPath('data.status', 'RECEIVED');
            $api->getJson('/api/v1/revenues?status=received')->assertJsonPath('meta.total', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_references_must_be_active_revenue_categories_and_customers_of_the_same_company(): void
    {
        $expense = $this->category($this->orgA, 'EXPENSE', 'Combustível');
        $foreignCustomer = $this->customer($this->orgB);
        $foreignCategory = $this->category($this->orgB);
        $api = $this->as($this->adminA, $this->orgA);
        $api->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $expense), $this->key())
            ->assertJsonPath('errors.category_id.0', 'Escolha uma categoria de receita.');
        $api->postJson('/api/v1/revenues', $this->revenuePayload($foreignCustomer, $this->categoryA), $this->key())
            ->assertJsonPath('errors.customer_id.0', 'Escolha um cliente desta empresa.');
        $api->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $foreignCategory), $this->key())
            ->assertJsonPath('errors.category_id.0', 'Escolha uma categoria desta empresa.');
        $api->postJson('/api/v1/revenues', [...$this->revenuePayload($this->customerA, $this->categoryA), 'organization_id' => $this->orgB->id], $this->key())
            ->assertJsonValidationErrors('organization_id');
        $this->assertDatabaseCount('revenues', 0);
    }

    public function test_admin_and_operator_create_and_settle_master_has_no_financial_access(): void
    {
        $revenue = $this->createRevenue([], $this->operatorA);
        $this->receipt($revenue, '100', [], 'CASH', null, $this->operatorA)->assertCreated();
        $this->as($this->operatorA, $this->orgA)->getJson('/api/v1/revenues')->assertOk()->assertJsonPath('meta.total', 1);
        $master = $this->as($this->master, $this->orgA);
        $master->getJson("/api/v1/revenues/{$revenue['id']}")->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
        $master->postJson("/api/v1/receivables/{$revenue['receivable']['id']}/receipts", ['amount' => '1', 'method' => 'PIX', 'received_at' => now()->subMinute()->toIso8601String()], $this->key())->assertForbidden();
        $this->assertDatabaseHas('receipts', ['created_by' => $this->operatorA->id, 'organization_id' => $this->orgA->id]);
    }

    public function test_creation_and_receipt_write_the_financial_audit_in_the_same_operation(): void
    {
        $revenue = $this->createRevenue();
        $this->receipt($revenue, '400')->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['organization_id' => $this->orgA->id, 'action' => 'revenue.created', 'entity_type' => 'Revenue', 'entity_id' => $revenue['id'], 'user_id' => $this->adminA->id]);
        $log = AuditLog::query()->where('action', 'receipt.created')->firstOrFail();
        $this->assertSame('600.00', $log->new_data['receivable']['remaining_amount']);
        $this->assertSame('1000.00', $log->previous_data['receivable']['remaining_amount']);
    }

    public function test_an_audit_failure_rolls_back_the_revenue_the_obligation_and_the_receipt(): void
    {
        $revenue = $this->createRevenue();
        $this->app->bind(AuditTrail::class, fn ($app) => new class($app->make(OrganizationContext::class)) extends AuditTrail
        {
            public function record(User $user, string $action, Model $entity, ?array $before, ?array $after): AuditLog
            {
                throw new RuntimeException('simulated audit failure');
            }
        });
        $this->as($this->adminA, $this->orgA)->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, ['description' => 'Não deve existir']), $this->key())->assertStatus(500);
        $this->receipt($revenue, '400')->assertStatus(500);
        $this->assertDatabaseMissing('revenues', ['description' => 'Não deve existir']);
        $this->assertDatabaseCount('revenues', 1);
        $this->assertDatabaseCount('receivables', 1);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseCount('idempotency_keys', 1); // only the first, successful creation
    }

    public function test_list_searches_and_paginates_with_exact_amounts(): void
    {
        $this->createRevenue(['description' => 'Pacote de 10 aulas', 'total_amount' => '1000.00']);
        $this->createRevenue(['description' => 'Exame prático', 'total_amount' => '150.00']);
        $api = $this->as($this->adminA, $this->orgA);
        $api->getJson('/api/v1/revenues?per_page=1')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2);
        $api->getJson('/api/v1/revenues?search=exame')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_amount', '150.00');
        $api->getJson('/api/v1/revenues?search=Cliente%20Sint')->assertJsonPath('meta.total', 2);
        $api->getJson('/api/v1/revenues?status=late')->assertJsonValidationErrors('status');
    }
}
