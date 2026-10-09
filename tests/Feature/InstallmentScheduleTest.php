<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

/** Card 10 (ADR-032): down payment, instalments with their own due dates/methods and internal reminders. */
class InstallmentScheduleTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    private string $customerA;

    private string $categoryA;

    private string $expenseCategoryA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00:00', 'America/Sao_Paulo'));
        $this->setUpCompanies();
        $this->customerA = $this->customer($this->orgA);
        $this->categoryA = $this->category($this->orgA);
        $this->expenseCategoryA = $this->category($this->orgA, 'EXPENSE', 'Manutenção');
    }

    /** Sale of 1200: down payment 400 received now via cash, then 4 × 200 on the 10th of the next months. */
    private function schedule(array $overrides = []): array
    {
        $lines = [['amount' => '400.00', 'down_payment' => true, 'settlement' => ['method' => 'CASH', 'received_at' => '2026-10-08T14:00:00-03:00']]];
        foreach (['2026-11-10', '2026-12-10', '2027-01-10', '2027-02-10'] as $index => $due) {
            $lines[] = ['amount' => '200.00', 'due_date' => $due, 'expected_method' => $index % 2 ? 'CARD' : 'PIX', 'reminder_date' => CarbonImmutable::parse($due)->subDays(2)->toDateString()];
        }

        return $this->revenuePayload($this->customerA, $this->categoryA, [
            'total_amount' => '1200.00', 'sale_date' => '2026-10-08', 'due_date' => null, 'expected_method' => null, 'installments' => $lines, ...$overrides,
        ]);
    }

    private function create(array $payload, ?array $key = null)
    {
        return $this->as($this->adminA, $this->orgA)->postJson('/api/v1/revenues', array_filter($payload, fn ($value) => $value !== null), $key ?? $this->key());
    }

    private function receive(string $installmentId, string $amount, ?array $key = null)
    {
        return $this->as($this->adminA, $this->orgA)->postJson("/api/v1/receivables/{$installmentId}/receipts", [
            'amount' => $amount, 'method' => 'PIX', 'received_at' => now()->subMinute()->utc()->format('Y-m-d\TH:i:sP'),
        ], $key ?? $this->key());
    }

    private function reminders(): array
    {
        return $this->as($this->adminA, $this->orgA)->getJson('/api/v1/reminders')->assertOk()->json('data');
    }

    public function test_acceptance_1200_with_400_down_payment_and_four_independent_instalments(): void
    {
        $data = $this->create($this->schedule())->assertCreated()
            ->assertJsonPath('data.received_amount', '400.00')
            ->assertJsonPath('data.remaining_amount', '800.00')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.receivable.due_date', '2026-11-10')
            ->json('data');
        $this->assertCount(5, $data['installments']);
        $this->assertSame([true, false, false, false, false], array_column($data['installments'], 'is_down_payment'));
        $this->assertSame(['RECEIVED', 'PENDING', 'PENDING', 'PENDING', 'PENDING'], array_column($data['installments'], 'status'));
        $this->assertSame(['2026-10-08', '2026-11-10', '2026-12-10', '2027-01-10', '2027-02-10'], array_column($data['installments'], 'due_date'));
        $this->assertSame(['CASH', 'PIX', 'CARD', 'PIX', 'CARD'], array_column($data['installments'], 'expected_method'));
        $this->assertSame([null, '2026-11-08', '2026-12-08', '2027-01-08', '2027-02-08'], array_column($data['installments'], 'reminder_date'));
        $this->assertSame([1, 2, 3, 4, 5], array_column($data['installments'], 'sequence'));
        $this->assertCount(1, $data['receipts']);

        // Only the explicit down payment became cash; the four instalments stay outstanding.
        $dashboard = $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-10')->assertOk()->json('data');
        $this->assertSame('400.00', $dashboard['monthly']['income']);
        $this->assertSame('800.00', $dashboard['outstanding']['receivable']);
        $list = $this->as($this->adminA, $this->orgA)->getJson('/api/v1/revenues')->assertOk()->json('data.0');
        $this->assertSame(['800.00', '400.00', 5], [$list['remaining_amount'], $list['received_amount'], count($list['installments'])]);
        $this->assertSame(1, AuditLog::query()->where('action', 'revenue.created')->count());
        $this->assertCount(5, AuditLog::query()->where('action', 'revenue.created')->first()->new_data['receivables']);
    }

    public function test_down_payment_may_stay_pending_and_nothing_is_settled_by_dates(): void
    {
        $payload = $this->schedule();
        $payload['installments'][0] = ['amount' => '400.00', 'down_payment' => true, 'due_date' => '2026-10-08', 'expected_method' => 'PIX'];
        $data = $this->create($payload)->assertCreated()->assertJsonPath('data.received_amount', '0.00')->json('data');
        $this->assertSame('PENDING', $data['installments'][0]['status']);
        $this->assertSame(0, DB::table('receipts')->count());
    }

    public function test_partial_settlement_respects_the_instalment_balance_and_retries(): void
    {
        $data = $this->create($this->schedule())->assertCreated()->json('data');
        $second = $data['installments'][1]['id'];
        $this->receive($second, '200.01')->assertUnprocessable()->assertJsonPath('errors.amount.0', 'O valor excede o restante a receber de R$ 200,00.');
        $key = $this->key();
        $after = $this->receive($second, '50.00', $key)->assertCreated()->json('data');
        $this->receive($second, '50.00', $key)->assertCreated();
        $this->assertSame(['150.00', 'PENDING'], [$after['installments'][1]['remaining_amount'], $after['installments'][1]['status']]);
        $this->assertSame('750.00', $after['remaining_amount']);
        $this->assertSame(2, DB::table('receipts')->count());
    }

    public function test_reminders_appear_on_the_company_day_and_show_only_what_remains(): void
    {
        $data = $this->create($this->schedule())->assertCreated()->json('data');
        $this->assertSame(0, $this->reminders()['due']['total']);
        $this->travelTo(CarbonImmutable::parse('2026-11-08 00:30:00', 'America/Sao_Paulo'));
        $reminders = $this->reminders();
        $this->assertSame('2026-11-08', $reminders['today']);
        $this->assertSame(1, $reminders['due']['total']);
        $item = $reminders['due']['items'][0];
        $this->assertSame([$data['installments'][1]['id'], '200.00', 2, 5, 1, 4], [$item['installment_id'], $item['remaining_amount'], $item['sequence'], $item['instalments_count'], $item['number'], $item['instalments_total']]);
        // Partial receipt: the reminder shows only the remaining amount; full receipt: it disappears.
        $this->receive($data['installments'][1]['id'], '120.00')->assertCreated();
        $this->assertSame('80.00', $this->reminders()['due']['items'][0]['remaining_amount']);
        $this->receive($data['installments'][1]['id'], '80.00')->assertCreated();
        $this->assertSame(0, $this->reminders()['due']['total']);
        // Other company sees nothing; a reminder never creates a receipt by itself.
        $this->assertSame(0, $this->as($this->adminB, $this->orgB)->getJson('/api/v1/reminders')->assertOk()->json('data.due.total'));
        $this->assertSame(3, DB::table('receipts')->count());
        $this->as($this->master, $this->orgA)->getJson('/api/v1/reminders')->assertForbidden();
    }

    public function test_upcoming_reminders_and_overdue_status_of_an_open_instalment(): void
    {
        $this->create($this->schedule())->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00', 'America/Sao_Paulo'));
        $this->assertSame(1, $this->reminders()['upcoming']['total']);
        $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00', 'America/Sao_Paulo'));
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/revenues?status=overdue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'OVERDUE');
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-11')->assertOk()->assertJsonPath('data.outstanding.overdue_receivable', '200.00');
    }

    public function test_schedule_validation_and_rollback_leave_nothing_behind(): void
    {
        $sum = $this->schedule();
        $sum['installments'][4]['amount'] = '199.99';
        $this->create($sum)->assertUnprocessable()->assertJsonPath('errors.installments.0', 'A soma das parcelas (R$ 1.199,99) precisa ser igual ao total (R$ 1.200,00).');

        $notFirst = $this->schedule();
        $notFirst['installments'][2]['down_payment'] = true;
        $this->create($notFirst)->assertUnprocessable()->assertJsonValidationErrors('installments.2.down_payment');

        $settledInstalment = $this->schedule();
        $settledInstalment['installments'][3] = ['amount' => '200.00', 'settlement' => ['method' => 'PIX', 'received_at' => '2026-10-08T10:00:00-03:00']];
        $this->create($settledInstalment)->assertUnprocessable()->assertJsonValidationErrors('installments.3.settlement');

        $missing = $this->schedule();
        unset($missing['installments'][1]['due_date'], $missing['installments'][2]['expected_method']);
        $this->create($missing)->assertUnprocessable()->assertJsonValidationErrors(['installments.1.due_date', 'installments.2.expected_method']);

        $beforeSale = $this->schedule();
        $beforeSale['installments'][1]['due_date'] = '2026-10-01';
        $this->create($beforeSale)->assertUnprocessable()->assertJsonValidationErrors('installments.1.due_date');

        $mixed = $this->schedule(['due_date' => '2026-10-20', 'settlement' => ['method' => 'PIX', 'received_at' => '2026-10-08T10:00:00-03:00']]);
        $this->create($mixed)->assertUnprocessable()->assertJsonValidationErrors(['due_date', 'settlement']);
        $this->create($this->schedule(['installments' => [['amount' => '1200.00', 'due_date' => '2026-11-10', 'expected_method' => 'PIX']]]))->assertUnprocessable()->assertJsonValidationErrors('installments');

        // A failing write (the down payment receipt audit) rolls back sale, instalments, receipt and key.
        $this->app->bind(AuditTrail::class, fn ($app) => new class($app->make(OrganizationContext::class)) extends AuditTrail
        {
            public function record(User $user, string $action, Model $entity, ?array $before, ?array $after): AuditLog
            {
                if ($action === 'receipt.created') {
                    throw new RuntimeException('simulated failure');
                }

                return parent::record($user, $action, $entity, $before, $after);
            }
        });
        $this->create($this->schedule())->assertStatus(500);
        foreach (['revenues', 'receivables', 'receipts', 'idempotency_keys', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_same_submission_twice_creates_one_schedule(): void
    {
        $key = $this->key();
        $first = $this->create($this->schedule(), $key)->assertCreated()->json('data.id');
        $this->create($this->schedule(), $key)->assertCreated()->assertJsonPath('data.id', $first);
        $this->assertSame([1, 5, 1], [DB::table('revenues')->count(), DB::table('receivables')->count(), DB::table('receipts')->count()]);
    }

    public function test_expense_schedule_with_paid_down_payment(): void
    {
        $data = $this->as($this->adminA, $this->orgA)->postJson('/api/v1/expenses', [
            'category_id' => (int) $this->expenseCategoryA, 'description' => 'Reforma do escritório', 'total_amount' => '1000.00', 'notes' => null,
            'installments' => [
                ['amount' => '100.00', 'down_payment' => true, 'settlement' => ['method' => 'PIX', 'paid_at' => '2026-10-08T09:00:00-03:00']],
                ['amount' => '300.00', 'due_date' => '2026-10-30', 'expected_method' => 'BOLETO', 'reminder_date' => '2026-10-28'],
                ['amount' => '300.00', 'due_date' => '2026-11-30', 'expected_method' => 'BOLETO'],
                ['amount' => '300.00', 'due_date' => '2026-12-31', 'expected_method' => 'BOLETO'],
            ],
        ], $this->key())->assertCreated()->assertJsonPath('data.paid_amount', '100.00')->assertJsonPath('data.remaining_amount', '900.00')
            ->assertJsonPath('data.payable.due_date', '2026-10-30')->json('data');
        $this->assertSame(['PAID', 'PENDING', 'PENDING', 'PENDING'], array_column($data['installments'], 'status'));
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/expenses?due_from=2026-11-01&due_to=2026-11-30')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/expenses?due_from=2027-01-01')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-10')->assertOk()
            ->assertJsonPath('data.monthly.expenses', '100.00')->assertJsonPath('data.outstanding.payable', '900.00');
    }

    public function test_legacy_single_obligation_and_simple_flow_keep_working_with_a_reminder(): void
    {
        $data = $this->as($this->adminA, $this->orgA)->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, [
            'total_amount' => '300.00', 'sale_date' => '2026-10-08', 'due_date' => '2026-10-20', 'reminder_date' => '2026-10-18',
        ]), $this->key())->assertCreated()->json('data');
        $this->assertCount(1, $data['installments']);
        $this->assertSame(['300.00', '2026-10-20', '2026-10-18', false], [$data['installments'][0]['amount'], $data['installments'][0]['due_date'], $data['installments'][0]['reminder_date'], $data['installments'][0]['is_down_payment']]);
        $this->assertSame($data['installments'][0]['id'], $data['receivable']['id']);
    }

    public function test_edit_and_delete_rules_for_schedules(): void
    {
        $data = $this->create($this->schedule())->assertCreated()->json('data');
        $edit = ['expected_version' => $data['version'], 'description' => 'Pacote completo de aulas', 'total_amount' => '1200.00', 'category_id' => (int) $this->categoryA,
            'customer_id' => (int) $this->customerA, 'sale_date' => '2026-10-08', 'due_date' => '2026-11-10', 'expected_method' => 'PIX', 'notes' => null];
        $this->as($this->adminA, $this->orgA)->patchJson("/api/v1/revenues/{$data['id']}", ['total_amount' => '1300.00'] + $edit, $this->key())
            ->assertUnprocessable()->assertJsonPath('errors.total_amount.0', 'Em lançamentos parcelados o total é a soma das parcelas e não muda nesta edição.');
        $edited = $this->as($this->adminA, $this->orgA)->patchJson("/api/v1/revenues/{$data['id']}", $edit, $this->key())->assertOk()->json('data');
        $this->assertSame('Pacote completo de aulas', $edited['description']);
        $this->assertSame(array_column($data['installments'], 'due_date'), array_column($edited['installments'], 'due_date'));
        // The received down payment blocks deletion (no refund flow); a schedule without receipts can be deleted.
        $this->as($this->adminA, $this->orgA)->deleteJson("/api/v1/revenues/{$data['id']}", ['expected_version' => $edited['version']], $this->key())->assertUnprocessable();
        $payload = $this->schedule();
        $payload['installments'][0] = ['amount' => '400.00', 'down_payment' => true, 'due_date' => '2026-10-08', 'expected_method' => 'PIX'];
        $open = $this->create($payload)->assertCreated()->json('data');
        $this->as($this->adminA, $this->orgA)->deleteJson("/api/v1/revenues/{$open['id']}", ['expected_version' => $open['version']], $this->key())->assertOk();
        $this->assertSame('800.00', $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-10')->json('data.outstanding.receivable'));
    }

    public function test_money_split_puts_the_leftover_cents_in_the_last_part(): void
    {
        $this->assertSame(['33.33', '33.33', '33.34'], Money::split('100.00', 3));
        $this->assertSame(['0.01', '0.01', '0.03'], Money::split('0.05', 3));
        $this->assertSame(['200.00', '200.00', '200.00', '200.00'], Money::split('800.00', 4));
        $this->assertSame('999999999.99', array_reduce(Money::split('999999999.99', 7), fn ($sum, $part) => Money::add($sum, $part), '0.00'));
    }
}
