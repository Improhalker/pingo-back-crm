<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Receipt;
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

/** Card 09 (ADR-031): corrections supersede a receipt/payment with a linked replacement; nothing is erased. */
class SettlementCorrectionTest extends TestCase
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
        $this->expenseCategoryA = $this->category($this->orgA, 'EXPENSE', 'Combustível');
    }

    private function revenue(string $total = '500.00'): array
    {
        return $this->as($this->adminA, $this->orgA)
            ->postJson('/api/v1/revenues', $this->revenuePayload($this->customerA, $this->categoryA, ['total_amount' => $total, 'sale_date' => '2026-09-20', 'due_date' => '2026-10-20']), $this->key())
            ->assertCreated()->json('data');
    }

    private function receive(array $revenue, string $amount, string $at = '2026-10-05T10:00:00-03:00'): array
    {
        return $this->as($this->adminA, $this->orgA)
            ->postJson("/api/v1/receivables/{$revenue['receivable']['id']}/receipts", ['amount' => $amount, 'method' => 'PIX', 'received_at' => $at], $this->key())
            ->assertCreated()->json('data');
    }

    private function correct(array $detail, string $receiptId, array $overrides = [], ?User $user = null, ?array $key = null)
    {
        return $this->as($user ?? $this->adminA, $this->orgA)->postJson("/api/v1/receipts/{$receiptId}/corrections", [
            'expected_version' => $detail['version'], 'amount' => '400.00', 'method' => 'PIX',
            'received_at' => '2026-10-05T10:00:00-03:00', 'reason' => 'Valor digitado errado no recebimento', ...$overrides,
        ], $key ?? $this->key());
    }

    private function dashboard(string $month): array
    {
        return $this->as($this->adminA, $this->orgA)->getJson("/api/v1/dashboard?month={$month}")->assertOk()->json('data');
    }

    public function test_acceptance_500_corrected_to_400_keeps_the_original_traceable(): void
    {
        $detail = $this->receive($this->revenue(), '500.00');
        $this->assertSame('RECEIVED', $detail['status']);
        $original = $detail['receipts'][0]['id'];

        $after = $this->correct($detail, $original)->assertCreated()
            ->assertJsonPath('data.received_amount', '400.00')
            ->assertJsonPath('data.remaining_amount', '100.00')
            ->assertJsonPath('data.status', 'PENDING')
            ->json('data');
        $this->assertCount(2, $after['receipts']);
        [$old, $new] = $after['receipts'];
        $this->assertSame(['CORRECTED', '500.00', $new['id']], [$old['status'], $old['amount'], $old['replaced_by_id']]);
        $this->assertSame(['EFFECTIVE', '400.00', $original, 'Valor digitado errado no recebimento'], [$new['status'], $new['amount'], $new['corrects_id'], $new['correction_reason']]);
        $this->assertNotNull($old['superseded_at']);

        // The original row is untouched apart from being superseded.
        $row = Receipt::query()->findOrFail($original);
        $this->assertSame('500.00', (string) $row->amount);
        $log = AuditLog::query()->where('action', 'receipt.corrected')->firstOrFail();
        $this->assertSame('500.00', $log->previous_data['receipt']['amount']);
        $this->assertSame('400.00', $log->new_data['receipt']['amount']);
        $this->assertSame('100.00', $log->new_data['obligation']['remaining_amount']);
        $this->assertSame('Valor digitado errado no recebimento', $log->new_data['reason']);

        $this->assertSame('400.00', $this->dashboard('2026-10')['monthly']['income']);
        $this->assertSame('100.00', $this->dashboard('2026-10')['outstanding']['receivable']);
    }

    public function test_date_correction_moves_the_amount_to_the_corrected_month(): void
    {
        $detail = $this->receive($this->revenue('300.00'), '300.00', '2026-09-30T23:30:00-03:00');
        $this->assertSame('300.00', $this->dashboard('2026-09')['monthly']['income']);
        $this->correct($detail, $detail['receipts'][0]['id'], ['amount' => '300.00', 'received_at' => '2026-10-01T09:00:00-03:00', 'reason' => 'Data do Pix estava errada'])->assertCreated();
        $this->assertSame('0.00', $this->dashboard('2026-09')['monthly']['income']);
        $this->assertSame('300.00', $this->dashboard('2026-10')['monthly']['income']);
    }

    public function test_retry_conflicts_and_versions_never_duplicate_corrections(): void
    {
        $detail = $this->receive($this->revenue(), '500.00');
        $receipt = $detail['receipts'][0]['id'];
        $key = $this->key();
        $first = $this->correct($detail, $receipt, key: $key)->assertCreated()->json('data');
        $this->correct($detail, $receipt, key: $key)->assertCreated()->assertJsonPath('data.version', $first['version']);
        $this->correct($detail, $receipt, ['amount' => '450.00'], key: $key)->assertStatus(409);
        $this->assertSame(2, Receipt::query()->count());

        // A new key on an already corrected receipt: the stale version is refused before anything else.
        $this->correct($detail, $receipt)->assertStatus(409)->assertJsonPath('code', 'FINANCIAL_ENTRY_CHANGED');
        // With the fresh version the corrected row itself still cannot be corrected again.
        $this->correct($first, $receipt)->assertStatus(409)->assertJsonPath('code', 'SETTLEMENT_ALREADY_CORRECTED');
        // A settlement registered meanwhile makes the reviewed version stale.
        $fresh = $this->receive($first, '50.00');
        $this->correct($first, $first['receipts'][1]['id'], ['amount' => '350.00'])->assertStatus(409)->assertJsonPath('code', 'FINANCIAL_ENTRY_CHANGED');
        // The replacement can be corrected with the current version: a chain, never an edit.
        $this->correct($fresh, $first['receipts'][1]['id'], ['amount' => '460.00', 'reason' => 'Segunda conferência do extrato'])->assertStatus(422)
            ->assertJsonPath('errors.amount.0', 'O valor corrigido não pode passar de R$ 450,00, o que falta desta parcela somado a esta baixa.');
        $this->correct($fresh, $first['receipts'][1]['id'], ['amount' => '410.00', 'reason' => 'Segunda conferência do extrato'])->assertCreated()
            ->assertJsonPath('data.received_amount', '460.00');
        $this->assertSame(1, Receipt::query()->whereNull('superseded_at')->whereNotNull('corrects_id')->count());
    }

    public function test_validation_requires_reason_a_real_change_and_a_valid_instant(): void
    {
        $detail = $this->receive($this->revenue(), '500.00');
        $receipt = $detail['receipts'][0]['id'];
        $this->correct($detail, $receipt, ['reason' => ' '])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->correct($detail, $receipt, ['amount' => '500.00'])->assertUnprocessable()->assertJsonPath('errors.amount.0', 'Nada mudou: altere o valor, a data/hora ou a forma para corrigir.');
        $this->correct($detail, $receipt, ['amount' => '500.01'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->correct($detail, $receipt, ['amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->correct($detail, $receipt, ['received_at' => '2026-10-09T10:00:00-03:00'])->assertUnprocessable()->assertJsonValidationErrors('received_at');
        $this->correct($detail, $receipt, ['corrects_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('corrects_id');
        // Only the method changes: allowed (a wrong method is also a typing error).
        $this->correct($detail, $receipt, ['amount' => '500.00', 'method' => 'CASH'])->assertCreated()->assertJsonPath('data.receipts.1.method', 'CASH');
    }

    public function test_isolation_roles_and_deleted_entries(): void
    {
        $detail = $this->receive($this->revenue(), '500.00');
        $receipt = $detail['receipts'][0]['id'];
        // Another company cannot see it; the master has no company context at all.
        $this->as($this->adminB, $this->orgB)->postJson("/api/v1/receipts/{$receipt}/corrections", [
            'expected_version' => $detail['version'], 'amount' => '400.00', 'method' => 'PIX', 'received_at' => '2026-10-05T10:00:00-03:00', 'reason' => 'Tentativa externa',
        ], $this->key())->assertNotFound();
        $this->as($this->master, $this->orgA)->postJson("/api/v1/receipts/{$receipt}/corrections", ['reason' => 'x'], $this->key())->assertForbidden();
        $this->assertSame(1, Receipt::query()->count());
        // OPERATOR may correct (decision of 08/10/2026), always with a reason and history.
        $this->correct($detail, $receipt, user: $this->operatorA)->assertCreated();
        $this->assertSame($this->operatorA->id, (int) Receipt::query()->whereNotNull('corrects_id')->value('created_by'));
    }

    public function test_failure_rolls_back_the_whole_correction(): void
    {
        $detail = $this->receive($this->revenue(), '500.00');
        $this->app->bind(AuditTrail::class, fn ($app) => new class($app->make(OrganizationContext::class)) extends AuditTrail
        {
            public function record(User $user, string $action, Model $entity, ?array $before, ?array $after): AuditLog
            {
                if ($action === 'receipt.corrected') {
                    throw new RuntimeException('simulated audit failure');
                }

                return parent::record($user, $action, $entity, $before, $after);
            }
        });
        $this->correct($detail, $detail['receipts'][0]['id'])->assertStatus(500);
        $this->assertSame(1, Receipt::query()->count());
        $this->assertSame(0, Receipt::query()->whereNotNull('superseded_at')->count());
        $this->assertSame($detail['version'], (int) DB::table('revenues')->value('version'));
        $this->assertSame(0, DB::table('idempotency_keys')->where('operation', 'receipt.correct')->count());
    }

    public function test_payment_correction_mirrors_receipts_and_keeps_deletion_blocked(): void
    {
        $expense = $this->as($this->adminA, $this->orgA)->postJson('/api/v1/expenses', [
            'category_id' => (int) $this->expenseCategoryA, 'description' => 'Abastecimento', 'total_amount' => '300.00', 'due_date' => '2026-10-10', 'expected_method' => 'PIX', 'notes' => null,
        ], $this->key())->assertCreated()->json('data');
        $paid = $this->as($this->adminA, $this->orgA)->postJson("/api/v1/payables/{$expense['payable']['id']}/payments", ['amount' => '300.00', 'method' => 'CARD', 'paid_at' => '2026-10-02T12:00:00-03:00'], $this->key())
            ->assertCreated()->json('data');
        $after = $this->as($this->operatorA, $this->orgA)->postJson("/api/v1/payments/{$paid['payments'][0]['id']}/corrections", [
            'expected_version' => $paid['version'], 'amount' => '280.00', 'method' => 'CARD', 'paid_at' => '2026-10-02T12:00:00-03:00', 'reason' => 'Cupom fiscal mostra R$ 280',
        ], $this->key())->assertCreated()->assertJsonPath('data.paid_amount', '280.00')->assertJsonPath('data.remaining_amount', '20.00')->json('data');
        $this->assertSame(['CORRECTED', 'EFFECTIVE'], array_column($after['payments'], 'status'));
        $this->assertSame('280.00', $this->dashboard('2026-10')['monthly']['expenses']);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.corrected')->count());
        // Corrected or not, an expense with effective payments still cannot be deleted (no refund flow yet).
        $this->as($this->adminA, $this->orgA)->deleteJson("/api/v1/expenses/{$expense['id']}", ['expected_version' => $after['version']], $this->key())->assertUnprocessable();
    }
}
