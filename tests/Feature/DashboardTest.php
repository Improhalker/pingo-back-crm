<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    private string $customerA;

    private string $revenueCategory;

    private string $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-02T12:00:00Z');
        $this->setUpCompanies();
        $this->customerA = $this->customer($this->orgA);
        $this->revenueCategory = $this->category($this->orgA);
        $this->expenseCategory = $this->category($this->orgA, 'EXPENSE', 'Combustível');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function sale(array $overrides = []): array
    {
        return $this->as($this->adminA, $this->orgA)->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->revenueCategory, [
            'sale_date' => '2026-09-01', 'due_date' => '2026-11-02', ...$overrides,
        ]), $this->key())->assertCreated()->json('data');
    }

    private function expense(array $overrides = []): array
    {
        return $this->as($this->adminA, $this->orgA)->postJson('/api/v1/expenses', [
            'category_id' => (int) $this->expenseCategory, 'description' => 'Combustível sintético',
            'total_amount' => '300.00', 'due_date' => '2026-11-02', 'expected_method' => 'PIX', ...$overrides,
        ], $this->key())->assertCreated()->json('data');
    }

    private function movement(string $table, array $source, string $amount, string $at): void
    {
        $receipt = $table === 'receipts';
        DB::table($table)->insert([
            'organization_id' => $this->orgA->id,
            $receipt ? 'receivable_id' : 'payable_id' => $source[$receipt ? 'receivable' : 'payable']['id'],
            'amount' => $amount, 'method' => 'PIX', $receipt ? 'received_at' : 'paid_at' => $at,
            'created_by' => $this->adminA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_card_acceptance_real_settlements_multiple_receipts_and_reload(): void
    {
        $sale = $this->sale();
        $expense = $this->expense();
        foreach (['100.25', '299.75'] as $amount) {
            $this->as($this->adminA, $this->orgA)->postJson("/api/v1/receivables/{$sale['receivable']['id']}/receipts", [
                'amount' => $amount, 'method' => 'PIX', 'received_at' => '2026-10-06T13:00:00Z',
            ], $this->key())->assertCreated();
        }
        foreach (['40.10', '59.90'] as $amount) {
            $this->as($this->adminA, $this->orgA)->postJson("/api/v1/payables/{$expense['payable']['id']}/payments", [
                'amount' => $amount, 'method' => 'CASH', 'paid_at' => '2026-10-06T15:00:00Z',
            ], $this->key())->assertCreated();
        }
        foreach ([$this->adminA, $this->operatorA] as $user) {
            $this->as($user, $this->orgA)->getJson('/api/v1/dashboard?month=2026-10')->assertOk()
                ->assertJsonPath('data.monthly', ['income' => '400.00', 'expenses' => '100.00', 'result' => '300.00'])
                ->assertJsonPath('data.outstanding.receivable', '600.00')->assertJsonPath('data.outstanding.payable', '200.00')
                ->assertJsonPath('data.daily.5', ['date' => '2026-10-06', 'income' => '400.00', 'expenses' => '100.00'])
                ->assertJsonPath('data.due.upcoming.total', 2)->assertJsonPath('data.due.overdue.total', 0)
                ->assertJsonCount(31, 'data.daily')->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_sale_and_expense_are_not_cash_and_outstanding_ignores_the_selected_month(): void
    {
        $sale = $this->sale(['due_date' => '2027-03-01']);
        $expense = $this->expense(['due_date' => '2026-09-20']);
        $this->movement('receipts', $sale, '0.10', '2026-10-06 13:00:00');
        $this->movement('receipts', $sale, '0.20', '2026-10-06 13:01:00');
        $this->movement('payments', $expense, '0.30', '2026-10-06 14:00:00');
        // Historical financial records survive archived catalog entries.
        DB::table('customers')->where('id', $this->customerA)->update(['archived_at' => now()]);
        DB::table('categories')->whereIn('id', [$this->revenueCategory, $this->expenseCategory])->update(['archived_at' => now()]);
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-09')->assertOk()
            ->assertJsonPath('data.monthly', ['income' => '0.00', 'expenses' => '0.00', 'result' => '0.00'])
            ->assertJsonPath('data.outstanding.receivable', '999.70')->assertJsonPath('data.outstanding.payable', '299.70')
            ->assertJsonPath('data.outstanding.overdue_payable', '299.70')->assertJsonPath('data.due.overdue.total', 1)
            ->assertJsonPath('data.due.upcoming.total', 0)->assertJsonPath('data.due.overdue.items.0.source_id', $expense['id']);
    }

    public function test_utc_month_boundaries_are_half_open_and_preserve_microseconds(): void
    {
        $sale = $this->sale();
        $expense = $this->expense();
        foreach ([['2026-10-01 02:59:59.999999', '10.00', '1.00'], ['2026-10-01 03:00:00.000000', '20.00', '2.00'], ['2026-11-01 02:59:59.999999', '30.00', '3.00'], ['2026-11-01 03:00:00.000000', '40.00', '4.00']] as [$at, $in, $out]) {
            $this->movement('receipts', $sale, $in, $at);
            $this->movement('payments', $expense, $out, $at);
        }
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-10')->assertOk()
            ->assertJsonPath('data.monthly', ['income' => '50.00', 'expenses' => '5.00', 'result' => '45.00'])
            ->assertJsonPath('data.daily.0', ['date' => '2026-10-01', 'income' => '20.00', 'expenses' => '2.00'])
            ->assertJsonPath('data.daily.30', ['date' => '2026-10-31', 'income' => '30.00', 'expenses' => '3.00'])
            ->assertJsonPath('data.outstanding.receivable', '900.00')->assertJsonPath('data.outstanding.payable', '290.00');
    }

    public function test_daylight_saving_repeated_hour_and_changed_month_offset(): void
    {
        CarbonImmutable::setTestNow('2026-12-02T12:00:00Z');
        $this->orgA->update(['timezone' => 'America/New_York']);
        $sale = $this->sale();
        foreach ([['2026-11-01 03:59:59.999999', '1.00'], ['2026-11-01 04:00:00', '2.00'], ['2026-11-01 05:30:00', '3.00'], ['2026-11-01 06:30:00', '4.00'], ['2026-12-01 04:59:59.999999', '5.00'], ['2026-12-01 05:00:00', '6.00']] as [$at, $amount]) {
            $this->movement('receipts', $sale, $amount, $at);
        }
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2026-11')->assertOk()
            ->assertJsonPath('data.period.timezone', 'America/New_York')->assertJsonPath('data.monthly.income', '14.00')
            ->assertJsonPath('data.daily.0.income', '9.00')->assertJsonPath('data.daily.29.income', '5.00');
    }

    public function test_due_today_is_not_overdue_uses_local_day_excludes_settled_and_limits_lists(): void
    {
        CarbonImmutable::setTestNow('2026-10-07T01:30:00Z'); // still Oct 6 in São Paulo
        $sale = $this->sale(['due_date' => '2026-10-06']);
        $settled = $this->expense(['due_date' => '2026-10-01']);
        $this->movement('payments', $settled, '300.00', '2026-10-06 13:00:00');
        for ($i = 0; $i < 8; $i++) {
            $this->expense(['due_date' => '2026-10-05']);
        }
        $this->expense(['due_date' => '2026-11-05']); // inclusive horizon
        $this->expense(['due_date' => '2026-11-06']); // outside the list, still outstanding
        $this->as($this->operatorA, $this->orgA)->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('data.today', '2026-10-06')->assertJsonPath('data.period.month', '2026-10')
            ->assertJsonPath('data.due.overdue.total', 8)->assertJsonCount(6, 'data.due.overdue.items')
            ->assertJsonPath('data.due.upcoming.total', 2)->assertJsonPath('data.due.upcoming.items.0.source_id', $sale['id'])
            ->assertJsonPath('data.outstanding.payable', '3000.00')->assertJsonPath('data.outstanding.overdue_payable', '2400.00')
            ->assertJsonPath('data.outstanding.overdue_receivable', '0.00');
    }

    public function test_negative_result_exact_cents_large_aggregates_and_leap_month(): void
    {
        $sale = $this->sale(['total_amount' => '0.30']);
        $expense = $this->expense(['total_amount' => '1.00']);
        $this->movement('receipts', $sale, '0.10', '2024-02-29 12:00:00');
        $this->movement('receipts', $sale, '0.20', '2024-02-29 13:00:00');
        $this->movement('payments', $expense, '1.00', '2024-02-29 14:00:00');
        $this->sale(['total_amount' => '999999999.99']);
        $this->sale(['total_amount' => '999999999.99']);
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard?month=2024-02')->assertOk()
            ->assertJsonCount(29, 'data.daily')->assertJsonPath('data.period.ends_on', '2024-02-29')
            ->assertJsonPath('data.monthly', ['income' => '0.30', 'expenses' => '1.00', 'result' => '-0.70'])
            ->assertJsonPath('data.outstanding.receivable', '1999999999.98')->assertJsonPath('data.outstanding.payable', '0.00');
    }

    public function test_isolation_master_denial_no_context_guest_and_suspended_company(): void
    {
        $sale = $this->sale();
        $this->movement('receipts', $sale, '400.00', '2026-10-06 13:00:00');
        $this->as($this->adminA, $this->orgB)->getJson('/api/v1/dashboard?month=2026-10')->assertNotFound();
        $this->as($this->master, $this->orgA)->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
        $this->as($this->adminB, $this->orgB)->getJson('/api/v1/dashboard?month=2026-10')->assertOk()
            ->assertJsonPath('data.monthly.income', '0.00')->assertJsonPath('data.outstanding.receivable', '0.00')
            ->assertJsonPath('data.due.upcoming.total', 0)->assertJsonCount(0, 'data.due.overdue.items');
        $this->as($this->adminA, $this->orgA)->withHeaders(['X-Organization-Id' => ''])->getJson('/api/v1/dashboard')->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->orgA->forceFill(['suspended_at' => now()])->save();
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_SUSPENDED');
    }

    public function test_empty_dashboard_and_month_validation(): void
    {
        $api = $this->as($this->operatorA, $this->orgA);
        $api->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('data.period.month', '2026-11')
            ->assertJsonPath('data.monthly', ['income' => '0.00', 'expenses' => '0.00', 'result' => '0.00'])
            ->assertJsonPath('data.outstanding.receivable', '0.00')->assertJsonPath('data.outstanding.payable', '0.00')
            ->assertJsonCount(30, 'data.daily');
        foreach (['2026-13', '2026-00', '2026-1', '1999-12', '2101-01', '2026-01-02', '', 'month[]=2026-10'] as $month) {
            $query = str_contains($month, '[]=') ? $month : 'month='.urlencode($month);
            $api->getJson('/api/v1/dashboard?'.$query)->assertJsonValidationErrors('month');
        }
        $api->getJson('/api/v1/dashboard?timezone=UTC')->assertJsonValidationErrors('timezone');
        $api->getJson('/api/v1/dashboard?organization_id='.$this->orgB->id)->assertJsonValidationErrors('organization_id');
    }
}
