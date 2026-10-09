<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

/** Card 11 (ADR-033): monthly report, cash statement and forecast of open instalments. */
class ReportsTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    private string $customer;

    private string $lessons;

    private string $packages;

    private string $fuel;

    private array $partialSale;

    private array $instalmentSale;

    private array $expense;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'America/Sao_Paulo'));
        $this->setUpCompanies();
        $this->customer = $this->customer($this->orgA);
        $this->lessons = $this->category($this->orgA);
        $this->packages = $this->category($this->orgA, 'REVENUE', 'Pacotes');
        $this->fuel = $this->category($this->orgA, 'EXPENSE', 'Combustível');
    }

    private function report(string $uri, array $query = [])
    {
        return $this->as($this->adminA, $this->orgA)->getJson($uri.($query ? '?'.http_build_query($query) : ''));
    }

    private function sale(array $overrides): array
    {
        return $this->as($this->adminA, $this->orgA)->postJson('/api/v1/revenues', array_filter(
            $this->revenuePayload($this->customer, $this->lessons, $overrides), fn ($value) => $value !== null,
        ), $this->key())->assertCreated()->json('data');
    }

    private function receive(array $sale, string $amount, string $at, string $method = 'PIX'): void
    {
        $this->as($this->adminA, $this->orgA)->postJson("/api/v1/receivables/{$sale['receivable']['id']}/receipts", [
            'amount' => $amount, 'method' => $method, 'received_at' => $at,
        ], $this->key())->assertCreated();
    }

    /**
     * October 2026, company in São Paulo:
     * - sale of 1000 (due 10/10, now overdue) with a partial receipt of 300;
     * - sale of 500 (due 05/11) never received;
     * - sale of 1200: down payment 400 received in cash + 4 × 200 (instalments 2 and 4 by card);
     * - expense of 300 paid in cash and corrected to 280 by card (20 remain);
     * - September: sale of 200 received at 30/09 23:30 local (already 01/10 in UTC).
     */
    private function seedOctober(): void
    {
        $this->partialSale = $this->sale(['description' => 'Pacote parcial', 'total_amount' => '1000.00', 'sale_date' => '2026-10-01', 'due_date' => '2026-10-10']);
        $this->receive($this->partialSale, '300.00', '2026-10-05T10:00:00-03:00');
        $this->sale(['description' => 'Venda a receber', 'total_amount' => '500.00', 'sale_date' => '2026-10-15', 'due_date' => '2026-11-05']);
        $lines = [['amount' => '400.00', 'down_payment' => true, 'settlement' => ['method' => 'CASH', 'received_at' => '2026-10-08T14:00:00-03:00']]];
        foreach (['2026-11-10', '2026-12-10', '2027-01-10', '2027-02-10'] as $index => $due) {
            $lines[] = ['amount' => '200.00', 'due_date' => $due, 'expected_method' => $index % 2 ? 'CARD' : 'PIX'];
        }
        $this->instalmentSale = $this->sale([
            'category_id' => (int) $this->packages, 'description' => 'Pacote parcelado', 'total_amount' => '1200.00', 'sale_date' => '2026-10-08',
            'due_date' => null, 'expected_method' => null, 'installments' => $lines,
        ]);
        $september = $this->sale(['description' => 'Aula de setembro', 'total_amount' => '200.00', 'sale_date' => '2026-09-20', 'due_date' => '2026-09-30']);
        $this->receive($september, '200.00', '2026-09-30T23:30:00-03:00');

        $expense = $this->as($this->adminA, $this->orgA)->postJson('/api/v1/expenses', [
            'category_id' => (int) $this->fuel, 'description' => 'Abastecimento', 'total_amount' => '300.00', 'due_date' => '2026-10-12', 'expected_method' => 'PIX',
        ], $this->key())->assertCreated()->json('data');
        $paid = $this->as($this->adminA, $this->orgA)->postJson("/api/v1/payables/{$expense['payable']['id']}/payments", [
            'amount' => '300.00', 'method' => 'CASH', 'paid_at' => '2026-10-12T09:00:00-03:00',
        ], $this->key())->assertCreated()->json('data');
        $this->expense = $this->as($this->operatorA, $this->orgA)->postJson("/api/v1/payments/{$paid['payments'][0]['id']}/corrections", [
            'expected_version' => $paid['version'], 'amount' => '280.00', 'method' => 'CARD', 'paid_at' => '2026-10-12T09:00:00-03:00', 'reason' => 'Cupom mostra R$ 280',
        ], $this->key())->assertCreated()->json('data');
    }

    public function test_monthly_report_matches_the_dashboard_and_separates_sold_from_cash(): void
    {
        $this->seedOctober();
        $dashboard = $this->report('/api/v1/dashboard', ['month' => '2026-10'])->assertOk()->json('data.monthly');
        $report = $this->report('/api/v1/reports/monthly', ['from' => '2026-09', 'to' => '2026-10'])->assertOk()
            ->assertJsonPath('data.basis', 'CASH')
            ->assertJsonPath('data.period', ['from' => '2026-09', 'to' => '2026-10', 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-31', 'timezone' => 'America/Sao_Paulo'])
            ->assertJsonPath('data.has_entries', true)
            ->json('data');

        [$september, $october] = $report['months'];
        $this->assertSame($dashboard, ['income' => $october['income'], 'expenses' => $october['expenses'], 'result' => $october['result']]);
        $this->assertSame(['income' => '700.00', 'expenses' => '280.00', 'result' => '420.00'], $dashboard);
        // 30/09 23:30 local is 01/10 in UTC and still belongs to September.
        $this->assertSame(['month' => '2026-09', 'income' => '200.00', 'expenses' => '0.00', 'result' => '200.00', 'result_change' => null, 'sold' => '200.00', 'expenses_due' => '0.00'], $september);
        $this->assertSame('220.00', $october['result_change']);
        // Sold uses the sale date (unreceived sales included); due expenses use the instalment due date.
        $this->assertSame('2700.00', $october['sold']);
        $this->assertSame('300.00', $october['expenses_due']);
        $this->assertSame(['income' => '900.00', 'expenses' => '280.00', 'result' => '620.00', 'sold' => '2900.00', 'expenses_due' => '300.00'], $report['totals']);

        // Categories (cash): the corrected payment counts once, with its corrected amount; shares in basis points.
        $this->assertSame([['Aulas práticas', '500.00', 2, 5555], ['Pacotes', '400.00', 1, 4444]],
            array_map(fn ($c) => [$c['name'], $c['amount'], $c['movements'], $c['share_bp']], $report['categories']['income']));
        $this->assertSame([['Combustível', '280.00', 1, 10000]],
            array_map(fn ($c) => [$c['name'], $c['amount'], $c['movements'], $c['share_bp']], $report['categories']['expenses']));

        // Defaults: six months ending in the current local month.
        $this->report('/api/v1/reports/monthly')->assertOk()->assertJsonPath('data.period.from', '2026-05')->assertJsonPath('data.period.to', '2026-10')
            ->assertJsonCount(6, 'data.months');
    }

    public function test_statement_lists_real_settlements_only_with_totals_of_the_whole_filtered_set(): void
    {
        $this->seedOctober();
        $all = $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk()
            ->assertJsonPath('basis', 'CASH')
            ->assertJsonPath('period.starts_on', '2026-10-01')->assertJsonPath('period.ends_on', '2026-10-31')
            ->assertJsonPath('summary', ['income' => '700.00', 'expenses' => '280.00', 'result' => '420.00', 'effective_count' => 3])
            ->assertJsonPath('meta.total', 3)
            ->json();
        // Chronological; the unreceived sale and the September receipt are not here.
        $this->assertSame([['INCOME', '300.00', 'PIX', '2026-10-05'], ['INCOME', '400.00', 'CASH', '2026-10-08'], ['EXPENSE', '280.00', 'CARD', '2026-10-12']],
            array_map(fn ($r) => [$r['kind'], $r['amount'], $r['method'], $r['local_date']], $all['data']));
        $this->assertSame(['sequence' => 1, 'number' => 0, 'total' => 4, 'is_down_payment' => true], $all['data'][1]['installment']);
        $this->assertSame((string) $this->instalmentSale['id'], $all['data'][1]['entry_id']);
        $this->assertSame(['Cliente Sintético', null], [$all['data'][0]['counterpart'], $all['data'][2]['counterpart']]);
        $this->assertTrue($all['data'][2]['is_correction']);
        $this->assertSame('2026-10-12T12:00:00.000Z', $all['data'][2]['occurred_at']);

        // The replaced payment can be listed for reference but never enters the totals.
        $withCorrected = $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'all'])->assertOk()
            ->assertJsonPath('summary.expenses', '280.00')->assertJsonPath('meta.total', 4)->json('data');
        $this->assertSame(['EFFECTIVE', 'EFFECTIVE', 'CORRECTED', 'EFFECTIVE'], array_column($withCorrected, 'status'));
        $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31', 'status' => 'corrected'])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('summary.expenses', '0.00')->assertJsonPath('data.0.amount', '300.00');

        // Filters change rows and totals together.
        $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31', 'type' => 'income'])->assertOk()
            ->assertJsonPath('summary', ['income' => '700.00', 'expenses' => '0.00', 'result' => '700.00', 'effective_count' => 2]);
        $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31', 'method' => 'CASH'])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('summary.income', '400.00')->assertJsonPath('summary.expenses', '0.00');
        $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31', 'category_id' => $this->lessons])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('summary.income', '300.00')->assertJsonPath('data.0.category.name', 'Aulas práticas');

        // Local day bounds: 30/09 23:30 in São Paulo.
        $this->report('/api/v1/reports/statement', ['from' => '2026-09-30', 'to' => '2026-09-30'])->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.local_date', '2026-09-30')->assertJsonPath('data.0.occurred_at', '2026-10-01T02:30:00.000Z');
        $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-01'])->assertOk()->assertJsonPath('meta.total', 0);

        // Default period: the current local month.
        $this->report('/api/v1/reports/statement')->assertOk()->assertJsonPath('period.starts_on', '2026-10-01')->assertJsonPath('meta.total', 3);
    }

    public function test_statement_pagination_is_stable_and_never_sums_only_the_page(): void
    {
        $this->seedOctober();
        $ids = [];
        foreach ([1, 2] as $page) {
            $response = $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31', 'per_page' => 2, 'page' => $page])->assertOk()
                ->assertJsonPath('summary.income', '700.00')->assertJsonPath('summary.result', '420.00')
                ->assertJsonPath('meta', ['current_page' => $page, 'last_page' => 2, 'per_page' => 2, 'total' => 3]);
            $ids = [...$ids, ...array_map(fn ($r) => $r['kind'].$r['id'], $response->json('data'))];
        }
        $full = array_map(fn ($r) => $r['kind'].$r['id'], $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31'])->json('data'));
        $this->assertSame($full, $ids);
        $desc = array_map(fn ($r) => $r['kind'].$r['id'], $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31', 'order' => 'desc'])->json('data'));
        $this->assertSame(array_reverse($full), $desc);
        $this->report('/api/v1/reports/statement', ['from' => '2026-10-01', 'to' => '2026-10-31', 'page' => 9])->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('summary.income', '700.00');
    }

    public function test_forecast_shows_what_remains_without_duplicating_principal_and_matches_the_dashboard(): void
    {
        $this->seedOctober();
        $outstanding = $this->report('/api/v1/dashboard', ['month' => '2026-10'])->json('data.outstanding');
        $report = $this->report('/api/v1/reports/obligations')->assertOk()
            ->assertJsonPath('basis', 'DUE_DATE')
            ->assertJsonPath('summary', ['receivable' => '2000.00', 'payable' => '20.00', 'difference' => '1980.00', 'overdue_receivable' => '700.00', 'overdue_payable' => '20.00'])
            ->assertJsonPath('meta.total', 7)
            ->json();
        $this->assertSame($outstanding, array_intersect_key($report['summary'], $outstanding));
        // Ordered by due date; the partial sale shows what was received and what remains.
        $this->assertSame([
            ['RECEIVABLE', '2026-10-10', '1000.00', '300.00', '700.00', 'OVERDUE'],
            ['PAYABLE', '2026-10-12', '300.00', '280.00', '20.00', 'OVERDUE'],
            ['RECEIVABLE', '2026-11-05', '500.00', '0.00', '500.00', 'PENDING'],
            ['RECEIVABLE', '2026-11-10', '200.00', '0.00', '200.00', 'PENDING'],
        ], array_map(fn ($r) => [$r['kind'], $r['due_date'], $r['amount'], $r['settled_amount'], $r['remaining_amount'], $r['status']], array_slice($report['data'], 0, 4)));
        $this->assertSame(['sequence' => 2, 'number' => 1, 'total' => 4, 'is_down_payment' => false], $report['data'][3]['installment']);
        $this->assertSame((string) $this->partialSale['id'], $report['data'][0]['entry_id']);

        $this->report('/api/v1/reports/obligations', ['status' => 'partial'])->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.receivable', '700.00')->assertJsonPath('summary.payable', '20.00');
        $this->report('/api/v1/reports/obligations', ['type' => 'receivable', 'status' => 'overdue'])->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary', ['receivable' => '700.00', 'payable' => '0.00', 'difference' => '700.00', 'overdue_receivable' => '700.00', 'overdue_payable' => '0.00']);
        $this->report('/api/v1/reports/obligations', ['due_from' => '2026-11-01', 'due_to' => '2026-11-30'])->assertOk()
            ->assertJsonPath('meta.total', 2)->assertJsonPath('summary.receivable', '700.00')->assertJsonPath('period.due_from', '2026-11-01');
        $this->report('/api/v1/reports/obligations', ['method' => 'CARD'])->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('summary.receivable', '400.00');
        $this->report('/api/v1/reports/obligations', ['category_id' => $this->fuel])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('summary.payable', '20.00');

        // A receipt moves the amount from the forecast to the statement, never both.
        $this->receive($this->partialSale, '700.00', '2026-10-20T10:00:00-03:00');
        $this->report('/api/v1/reports/obligations')->assertOk()->assertJsonPath('summary.receivable', '1300.00')->assertJsonPath('meta.total', 6);
        $this->report('/api/v1/reports/statement')->assertOk()->assertJsonPath('summary.income', '1400.00');
    }

    public function test_deleted_entries_are_excluded_and_empty_company_is_told_apart_from_empty_period(): void
    {
        $this->report('/api/v1/reports/statement')->assertOk()->assertJsonPath('has_entries', false)->assertJsonPath('meta.total', 0)
            ->assertJsonPath('summary.result', '0.00');
        $sale = $this->sale(['total_amount' => '150.00', 'sale_date' => '2026-10-02', 'due_date' => '2026-10-30']);
        $this->report('/api/v1/reports/obligations')->assertOk()->assertJsonPath('has_entries', true)->assertJsonPath('summary.receivable', '150.00');
        $this->report('/api/v1/reports/statement', ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk()
            ->assertJsonPath('has_entries', true)->assertJsonPath('meta.total', 0);

        $this->as($this->adminA, $this->orgA)->deleteJson("/api/v1/revenues/{$sale['id']}", ['expected_version' => $sale['version']], $this->key())->assertOk();
        $this->report('/api/v1/reports/obligations')->assertOk()->assertJsonPath('has_entries', false)->assertJsonPath('meta.total', 0);
        $this->report('/api/v1/reports/monthly', ['from' => '2026-10', 'to' => '2026-10'])->assertOk()->assertJsonPath('data.months.0.sold', '0.00');
    }

    public function test_isolation_roles_and_master(): void
    {
        $this->seedOctober();
        foreach (['/api/v1/reports/monthly', '/api/v1/reports/statement', '/api/v1/reports/obligations'] as $uri) {
            $this->as($this->operatorA, $this->orgA)->getJson($uri)->assertOk();
            $this->as($this->master, $this->orgA)->getJson($uri)->assertForbidden();
            $this->as($this->adminB, $this->orgA)->getJson($uri)->assertNotFound();
            $this->app['auth']->forgetGuards();
            $this->withHeaders(['X-Organization-Id' => (string) $this->orgA->id])->getJson($uri)->assertUnauthorized();
        }
        // Company B sees none of A's data, even filtering by one of A's categories.
        $this->as($this->adminB, $this->orgB)->getJson('/api/v1/reports/statement?from=2026-10-01&to=2026-10-31&category_id='.$this->lessons)->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonPath('has_entries', false);
        $this->as($this->adminB, $this->orgB)->getJson('/api/v1/reports/obligations')->assertOk()->assertJsonPath('summary.receivable', '0.00');
        $this->as($this->adminB, $this->orgB)->getJson('/api/v1/reports/monthly?from=2026-10&to=2026-10')->assertOk()
            ->assertJsonPath('data.totals.income', '0.00')->assertJsonPath('data.categories.income', []);
    }

    public function test_validation_of_periods_and_filters(): void
    {
        $this->report('/api/v1/reports/statement', ['from' => '2024-01-01', 'to' => '2024-12-31'])->assertOk();
        $this->report('/api/v1/reports/statement', ['from' => '2026-01-01', 'to' => '2027-01-02'])->assertUnprocessable()
            ->assertJsonPath('errors.to.0', 'Escolha um período de até 366 dias.');
        $this->report('/api/v1/reports/statement', ['from' => '2026-10-10', 'to' => '2026-10-01'])->assertUnprocessable()
            ->assertJsonPath('errors.to.0', 'O fim do período não pode ser anterior ao início.');
        $this->report('/api/v1/reports/statement', ['from' => '2026-02-30'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->report('/api/v1/reports/statement', ['method' => 'GOLD', 'type' => 'all', 'status' => 'x', 'order' => 'up', 'per_page' => 101])->assertUnprocessable()
            ->assertJsonValidationErrors(['method', 'type', 'status', 'order', 'per_page']);
        $this->report('/api/v1/reports/statement', ['organization_id' => $this->orgB->id])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->report('/api/v1/reports/obligations', ['due_from' => '2026-01-01', 'due_to' => '2027-06-01'])->assertUnprocessable()->assertJsonValidationErrors('due_to');
        $this->report('/api/v1/reports/obligations', ['status' => 'received', 'type' => 'income'])->assertUnprocessable()->assertJsonValidationErrors(['status', 'type']);
        $this->report('/api/v1/reports/monthly', ['from' => '2025-10', 'to' => '2026-10'])->assertUnprocessable()->assertJsonPath('errors.to.0', 'Escolha até 12 meses.');
        $this->report('/api/v1/reports/monthly', ['from' => '2026-10', 'to' => '2026-09'])->assertUnprocessable()->assertJsonPath('errors.to.0', 'O mês final não pode ser anterior ao inicial.');
        $this->report('/api/v1/reports/monthly', ['from' => '2026-13'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->report('/api/v1/reports/monthly', ['from' => '2025-11', 'to' => '2026-10'])->assertOk()->assertJsonCount(12, 'data.months');
    }

    public function test_reports_only_read(): void
    {
        $this->seedOctober();
        $before = DB::table('audit_logs')->count();
        $this->report('/api/v1/reports/monthly')->assertOk();
        $this->report('/api/v1/reports/statement')->assertOk();
        $this->report('/api/v1/reports/obligations')->assertOk();
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->as($this->adminA, $this->orgA)->postJson('/api/v1/reports/statement')->assertStatus(405);
    }
}
