<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

final class FinancialRecurrenceTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    private string $source;

    private string $incomeCategory;

    private string $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-23T14:00:00-03:00'));
        $this->setUpCompanies();
        $this->source = $this->customer($this->orgA, 'Fonte pagadora sintética');
        $this->incomeCategory = $this->category($this->orgA);
        $this->expenseCategory = $this->category($this->orgA, 'EXPENSE', 'Contas fixas');
    }

    private function makeSeries(string $kind = 'EXPENSE', array $overrides = [], ?array $key = null)
    {
        return $this->as($this->operatorA, $this->orgA)->postJson('/api/v1/recurrences', [
            'kind' => $kind, 'category_id' => $kind === 'EXPENSE' ? $this->expenseCategory : $this->incomeCategory,
            ...($kind === 'REVENUE' ? ['customer_id' => $this->source] : []),
            'description' => 'Conta fixa sintética', 'amount' => '100.00', 'expected_method' => 'PIX',
            'due_day' => 31, 'reminder_days' => 1, 'starts_month' => '2026-11',
            ...$overrides,
        ], $key ?? $this->key());
    }

    private function change(array $series, string $action, array $extra = [])
    {
        return $this->as($this->adminA, $this->orgA)->patchJson('/api/v1/recurrences/'.$series['id'], [
            'action' => $action, 'expected_version' => $series['version'], ...$extra,
        ], $this->key());
    }

    public function test_generation_is_monthly_idempotent_and_only_manual_receipt_hits_cash(): void
    {
        $key = $this->key();
        $revenue = $this->makeSeries('REVENUE', [], $key)->assertCreated()->assertJsonPath('data.next_due_date', '2026-11-30')->json('data');
        $this->makeSeries('REVENUE', [], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $expense = $this->makeSeries()->assertCreated()->json('data');
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->assertDatabaseCount('revenues', 2);
        $this->assertDatabaseCount('expenses', 2);
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseCount('payments', 0);

        $api = $this->as($this->adminA, $this->orgA);
        $api->getJson('/api/v1/recurrences/'.$expense['id'])->assertOk()
            ->assertJsonPath('data.occurrences.0.month', '2026-12')
            ->assertJsonPath('data.occurrences.1.due_date', '2026-11-30');
        $api->getJson('/api/v1/dashboard?month=2026-11')->assertOk()
            ->assertJsonPath('data.due_in_month', ['receivable' => '100.00', 'payable' => '100.00'])
            ->assertJsonPath('data.monthly.income', '0.00');

        $instalment = DB::table('receivables')->where('due_date', '2026-11-30')->first();
        $api->postJson('/api/v1/receivables/'.$instalment->id.'/receipts', [
            'amount' => '40.00', 'method' => 'PIX', 'received_at' => '2026-11-23T10:00:00-03:00',
        ], $this->key())->assertCreated()->assertJsonPath('data.recurrence.id', $revenue['id']);
        $api->getJson('/api/v1/dashboard?month=2026-11')->assertOk()
            ->assertJsonPath('data.due_in_month.receivable', '60.00')
            ->assertJsonPath('data.monthly.income', '40.00');
        $api->getJson('/api/v1/reminders')->assertOk()
            ->assertJsonPath('data.upcoming.items.0.reminder_date', '2026-11-29');
    }

    public function test_reajuste_updates_generated_future_and_keeps_current_paid_and_manual_exceptions(): void
    {
        $normal = $this->makeSeries()->assertCreated()->json('data');
        $paid = $this->makeSeries()->assertCreated()->json('data');
        $exception = $this->makeSeries('REVENUE')->assertCreated()->json('data');
        $paidExpense = DB::table('expenses')->where('financial_recurrence_id', $paid['id'])->where('recurrence_month', '2026-12-01')->first();
        $payable = DB::table('payables')->where('expense_id', $paidExpense->id)->first();
        $futureRevenue = DB::table('revenues')->where('financial_recurrence_id', $exception['id'])->where('recurrence_month', '2026-12-01')->first();
        $api = $this->as($this->adminA, $this->orgA);
        $api->postJson('/api/v1/payables/'.$payable->id.'/payments', [
            'amount' => '15.00', 'method' => 'PIX', 'paid_at' => '2026-11-23T10:00:00-03:00',
        ], $this->key())->assertCreated();
        $api->patchJson('/api/v1/revenues/'.$futureRevenue->id, [
            'expected_version' => 1, 'description' => 'Mês ajustado pontualmente',
            'customer_id' => $this->source, 'category_id' => $this->incomeCategory, 'total_amount' => '130.00',
            'sale_date' => '2026-12-01', 'due_date' => '2026-12-31', 'expected_method' => 'PIX',
        ], $this->key())->assertOk();

        $this->change($normal, 'adjust', ['amount' => '200.00', 'effective_month' => '2026-11'])->assertJsonValidationErrors('effective_month');
        $first = $this->change($normal, 'adjust', ['amount' => '200.00', 'effective_month' => '2026-12'])->assertOk()->json('data');
        $this->change($normal, 'adjust', ['amount' => '999.00', 'effective_month' => '2026-12'])->assertConflict();
        $this->change($first, 'adjust', ['amount' => '210.00', 'effective_month' => '2026-12'])->assertOk();
        $this->change($paid, 'adjust', ['amount' => '250.00', 'effective_month' => '2026-12'])->assertOk();
        $this->change($exception, 'adjust', ['amount' => '240.00', 'effective_month' => '2026-12'])->assertOk();

        $this->assertDatabaseHas('expenses', ['financial_recurrence_id' => $normal['id'], 'recurrence_month' => '2026-11-01', 'total_amount' => '100.00']);
        $this->assertDatabaseHas('expenses', ['financial_recurrence_id' => $normal['id'], 'recurrence_month' => '2026-12-01', 'total_amount' => '210.00', 'version' => 3]);
        $this->assertDatabaseHas('expenses', ['id' => $paidExpense->id, 'total_amount' => '100.00']);
        $this->assertDatabaseHas('payments', ['payable_id' => $payable->id, 'amount' => '15.00']);
        $this->assertDatabaseHas('revenues', ['id' => $futureRevenue->id, 'total_amount' => '130.00', 'recurrence_amount_overridden' => 1]);
        $this->travelTo(CarbonImmutable::parse('2026-12-10T14:00:00-03:00'));
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->assertDatabaseHas('revenues', ['financial_recurrence_id' => $exception['id'], 'recurrence_month' => '2027-01-01', 'total_amount' => '240.00']);
    }

    public function test_pause_resume_end_and_deleted_occurrences_preserve_history(): void
    {
        $series = $this->makeSeries()->assertCreated()->json('data');
        $pause = $this->change($series, 'pause')->assertOk()->assertJsonPath('data.status', 'PAUSED')->json('data');
        $this->travelTo(CarbonImmutable::parse('2027-02-15T14:00:00-03:00'));
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->assertDatabaseCount('expenses', 2);
        $resume = $this->change($pause, 'resume')->assertOk()->json('data');
        $this->assertDatabaseCount('expenses', 4);
        $this->assertDatabaseMissing('expenses', ['financial_recurrence_id' => $series['id'], 'recurrence_month' => '2027-01-01']);
        $deleted = DB::table('expenses')->where('financial_recurrence_id', $series['id'])->where('recurrence_month', '2027-03-01')->first();
        $this->as($this->adminA, $this->orgA)->deleteJson('/api/v1/expenses/'.$deleted->id, ['expected_version' => 1], $this->key())->assertOk();
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->change($resume, 'end')->assertOk()->assertJsonPath('data.status', 'ENDED');
        $this->travelTo(CarbonImmutable::parse('2027-04-15T14:00:00-03:00'));
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->assertDatabaseCount('expenses', 4);
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/recurrences/'.$series['id'])
            ->assertOk()->assertJsonPath('data.occurrences.0.deleted', true);
    }

    public function test_last_day_of_leap_february_end_month_and_company_isolation(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-02-05T12:00:00-03:00'));
        $series = $this->makeSeries('EXPENSE', ['starts_month' => '2028-02', 'ends_month' => '2028-03'])->assertCreated()->json('data');
        $this->as($this->adminA, $this->orgA)->getJson('/api/v1/recurrences/'.$series['id'])->assertOk()
            ->assertJsonPath('data.occurrences.0.due_date', '2028-03-31')
            ->assertJsonPath('data.occurrences.1.due_date', '2028-02-29');
        $this->travelTo(CarbonImmutable::parse('2028-03-12T12:00:00-03:00'));
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->assertDatabaseCount('expenses', 2);
        $this->as($this->adminB, $this->orgB)->getJson('/api/v1/recurrences')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->adminB, $this->orgB)->getJson('/api/v1/recurrences/'.$series['id'])->assertNotFound();
        $this->as($this->master, $this->orgA)->getJson('/api/v1/recurrences')->assertForbidden();
        $this->makeSeries('EXPENSE', ['starts_month' => '2028-03', 'due_day' => 32])->assertJsonValidationErrors('due_day');
        $this->makeSeries('REVENUE', ['starts_month' => '2028-03', 'category_id' => $this->expenseCategory])->assertJsonValidationErrors('category_id');
    }

    public function test_scheduler_recovers_missed_months_and_skips_suspended_company(): void
    {
        $series = $this->makeSeries()->assertCreated()->json('data');
        $this->orgA->forceFill(['suspended_at' => now()])->save();
        $this->travelTo(CarbonImmutable::parse('2027-03-03T14:00:00-03:00'));
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->assertDatabaseCount('expenses', 2);
        $this->orgA->forceFill(['suspended_at' => null])->save();
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->artisan('finance:generate-recurrences')->assertSuccessful();
        $this->assertDatabaseCount('expenses', 6);
        $this->assertDatabaseHas('expenses', ['financial_recurrence_id' => $series['id'], 'recurrence_month' => '2027-01-01']);
        $this->assertDatabaseHas('expenses', ['financial_recurrence_id' => $series['id'], 'recurrence_month' => '2027-04-01']);
    }
}
