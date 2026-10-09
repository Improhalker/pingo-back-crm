<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

class FinancialEntryChangesTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCompanies();
    }

    private function entry(bool $revenue): array
    {
        $category = $this->category($this->orgA, $revenue ? 'REVENUE' : 'EXPENSE');
        $payload = $revenue ? $this->revenuePayload($this->customer($this->orgA), $category) : [
            'category_id' => (int) $category, 'description' => 'Despesa sintética', 'total_amount' => '1000.00',
            'due_date' => now('America/Sao_Paulo')->addDays(10)->toDateString(), 'expected_method' => 'PIX',
        ];

        return $this->as($this->adminA, $this->orgA)->postJson('/api/v1/'.($revenue ? 'revenues' : 'expenses'), $payload, $this->key())->assertCreated()->json('data');
    }

    private function path(bool $revenue, array $entry): string
    {
        return '/api/v1/'.($revenue ? 'revenues' : 'expenses').'/'.$entry['id'];
    }

    private function correction(bool $revenue, array $entry, array $changes = []): array
    {
        $obligation = $entry[$revenue ? 'receivable' : 'payable'];

        return [
            'description' => 'Descrição corrigida', 'category_id' => (int) $entry['category']['id'],
            'total_amount' => $entry['total_amount'], 'due_date' => $obligation['due_date'],
            'expected_method' => 'CASH', 'notes' => 'Observação corrigida', 'expected_version' => $entry['version'],
            ...($revenue ? ['customer_id' => (int) $entry['customer']['id'], 'sale_date' => $entry['sale_date']] : []),
            ...$changes,
        ];
    }

    private function settle(bool $revenue, array $entry, string $amount): array
    {
        $id = $entry[$revenue ? 'receivable' : 'payable']['id'];

        return $this->as($this->operatorA, $this->orgA)->postJson('/api/v1/'.($revenue ? 'receivables' : 'payables')."/{$id}/".($revenue ? 'receipts' : 'payments'), [
            'amount' => $amount, 'method' => 'PIX', ($revenue ? 'received_at' : 'paid_at') => now()->subMinute()->utc()->toIso8601String(),
        ], $this->key())->assertCreated()->json('data');
    }

    public function test_corrects_metadata_obligation_and_references_with_a_single_audited_idempotent_write(): void
    {
        foreach ([true, false] as $revenue) {
            $entry = $this->entry($revenue);
            $category = $this->category($this->orgA, $revenue ? 'REVENUE' : 'EXPENSE', 'Categoria corrigida');
            $changes = ['category_id' => (int) $category, 'total_amount' => '1234.56', 'due_date' => now('America/Sao_Paulo')->addDays(15)->toDateString()];
            if ($revenue) {
                $changes += ['customer_id' => (int) $this->customer($this->orgA, 'Outro cliente'), 'sale_date' => now('America/Sao_Paulo')->subDay()->toDateString()];
            }
            $payload = $this->correction($revenue, $entry, $changes);
            $key = $this->key();
            $path = $this->path($revenue, $entry);
            $response = $this->as($this->operatorA, $this->orgA)->patchJson($path, $payload, $key)->assertOk()
                ->assertJsonPath('data.version', 2)->assertJsonPath('data.description', $payload['description'])
                ->assertJsonPath('data.total_amount', '1234.56')->assertJsonPath('data.category.id', $category)
                ->assertJsonPath('data.'.($revenue ? 'receivable' : 'payable').'.due_date', $payload['due_date'])
                ->assertJsonPath('data.'.($revenue ? 'receivable' : 'payable').'.expected_method', 'CASH');
            if ($revenue) {
                $response->assertJsonPath('data.customer.id', (string) $payload['customer_id'])->assertJsonPath('data.sale_date', $payload['sale_date']);
            }
            $this->patchJson($path, $payload, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
            $this->patchJson($path, [...$payload, 'description' => 'Outra correção'], $key)->assertConflict();
            $this->assertSame(1, DB::table('audit_logs')->where('action', ($revenue ? 'revenue' : 'expense').'.updated')->count());
            $audit = AuditLog::where('action', ($revenue ? 'revenue' : 'expense').'.updated')->firstOrFail();
            $this->assertSame(1, $audit->previous_data['version']);
            $this->assertSame(2, $audit->new_data['version']);
            $this->assertSame('1234.56', $audit->new_data['total_amount']);
        }
    }

    public function test_preserves_partial_and_full_settlements_and_rejects_reducing_total_below_them(): void
    {
        foreach ([true, false] as $revenue) {
            $entry = $this->entry($revenue);
            $partial = $this->settle($revenue, $entry, '400.00');
            $this->assertSame(2, $partial['version']);
            $this->as($this->adminA, $this->orgA)->patchJson($this->path($revenue, $entry), $this->correction($revenue, $partial, ['total_amount' => '399.99']), $this->key())
                ->assertUnprocessable()->assertJsonValidationErrors('total_amount');
            $changed = $this->patchJson($this->path($revenue, $entry), $this->correction($revenue, $partial, ['total_amount' => '400.00']), $this->key())->assertOk()
                ->assertJsonPath('data.remaining_amount', '0.00')->assertJsonPath('data.'.($revenue ? 'received_amount' : 'paid_amount'), '400.00')
                ->assertJsonPath('data.status', $revenue ? 'RECEIVED' : 'PAID')->json('data');
            $this->patchJson($this->path($revenue, $entry), $this->correction($revenue, $changed), $this->key())->assertOk();
            $this->assertSame('400.00', (string) DB::table($revenue ? 'receipts' : 'payments')->sum('amount'));
            $this->assertSame('PIX', DB::table($revenue ? 'receipts' : 'payments')->value('method'));
            $this->deleteJson($this->path($revenue, $entry), ['expected_version' => $changed['version'] + 1], $this->key())->assertUnprocessable()->assertJsonValidationErrors('entry');
        }
    }

    public function test_soft_delete_disappears_from_lists_detail_and_dashboard_and_cannot_be_settled(): void
    {
        foreach ([true, false] as $revenue) {
            $entry = $this->entry($revenue);
            $path = $this->path($revenue, $entry);
            $payload = ['expected_version' => $entry['version']];
            $key = $this->key();
            $this->as($this->operatorA, $this->orgA)->deleteJson($path, $payload, $key)->assertOk()->assertJsonPath('data.deleted', true);
            $this->deleteJson($path, $payload, $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
            $this->getJson($path)->assertNotFound();
            $this->getJson('/api/v1/'.($revenue ? 'revenues' : 'expenses'))->assertOk()->assertJsonPath('meta.total', 0);
            $this->assertNotNull(DB::table($revenue ? 'revenues' : 'expenses')->where('id', $entry['id'])->value('deleted_at'));
            $this->assertSame(1, DB::table('audit_logs')->where('action', ($revenue ? 'revenue' : 'expense').'.deleted')->count());
            $obligation = $entry[$revenue ? 'receivable' : 'payable']['id'];
            $this->postJson('/api/v1/'.($revenue ? 'receivables' : 'payables')."/{$obligation}/".($revenue ? 'receipts' : 'payments'), [
                'amount' => '1.00', 'method' => 'PIX', ($revenue ? 'received_at' : 'paid_at') => now()->subMinute()->toIso8601String(),
            ], $this->key())->assertNotFound();
            $this->assertDatabaseCount($revenue ? 'receipts' : 'payments', 0);
        }
        $this->getJson('/api/v1/dashboard?month='.now('America/Sao_Paulo')->format('Y-m'))->assertOk()
            ->assertJsonPath('data.outstanding.receivable', '0.00')->assertJsonPath('data.outstanding.payable', '0.00');
    }

    public function test_stale_editor_cannot_overwrite_an_edit_or_a_new_settlement(): void
    {
        foreach ([true, false] as $revenue) {
            $entry = $this->entry($revenue);
            $path = $this->path($revenue, $entry);
            $partial = $this->settle($revenue, $entry, '100.00');
            $this->as($this->adminA, $this->orgA)->patchJson($path, $this->correction($revenue, $entry), $this->key())->assertConflict()->assertJsonPath('code', 'FINANCIAL_ENTRY_CHANGED');
            $this->deleteJson($path, ['expected_version' => 1], $this->key())->assertConflict();
            $this->patchJson($path, $this->correction($revenue, $partial), $this->key())->assertOk()->assertJsonPath('data.version', 3);
            $this->patchJson($path, $this->correction($revenue, $partial), $this->key())->assertConflict();
            $this->getJson($path)->assertJsonPath('data.total_amount', '1000.00')->assertJsonPath('data.version', 3);
        }
    }

    public function test_tenant_master_and_reference_validation_never_modify_foreign_or_invalid_data(): void
    {
        foreach ([true, false] as $revenue) {
            $entry = $this->entry($revenue);
            $path = $this->path($revenue, $entry);
            $payload = $this->correction($revenue, $entry);
            $this->as($this->adminB, $this->orgB)->patchJson($path, $payload, $this->key())->assertNotFound();
            $this->deleteJson($path, ['expected_version' => 1], $this->key())->assertNotFound();
            $this->as($this->master, $this->orgA)->patchJson($path, $payload, $this->key())->assertForbidden();
            $this->deleteJson($path, ['expected_version' => 1], $this->key())->assertForbidden();
            $foreign = $this->category($this->orgB, $revenue ? 'REVENUE' : 'EXPENSE');
            $wrongType = $this->category($this->orgA, $revenue ? 'EXPENSE' : 'REVENUE', 'Tipo incorreto');
            foreach ([$foreign, $wrongType] as $category) {
                $this->as($this->adminA, $this->orgA)->patchJson($path, [...$payload, 'category_id' => (int) $category], $this->key())->assertUnprocessable()->assertJsonValidationErrors('category_id');
            }
            $this->patchJson($path, [...$payload, 'total_amount' => '1.001'], $this->key())->assertUnprocessable();
            $this->patchJson($path, [...$payload, 'organization_id' => $this->orgB->id, 'settlement' => ['amount' => '1']], $this->key())->assertUnprocessable()->assertJsonValidationErrors(['organization_id', 'settlement']);
            $this->getJson($path)->assertJsonPath('data.version', 1);
        }
    }

    public function test_current_archived_references_can_be_retained_but_cannot_be_newly_selected(): void
    {
        $entry = $this->entry(true);
        DB::table('customers')->where('id', $entry['customer']['id'])->update(['archived_at' => now()]);
        DB::table('categories')->where('id', $entry['category']['id'])->update(['archived_at' => now()]);
        $otherCustomer = $this->customer($this->orgA, 'Cliente arquivado');
        $otherCategory = $this->category($this->orgA, 'REVENUE', 'Categoria arquivada');
        DB::table('customers')->where('id', $otherCustomer)->update(['archived_at' => now()]);
        DB::table('categories')->where('id', $otherCategory)->update(['archived_at' => now()]);
        $path = $this->path(true, $entry);
        $this->patchJson($path, $this->correction(true, $entry, ['customer_id' => (int) $otherCustomer]), $this->key())->assertUnprocessable()->assertJsonValidationErrors('customer_id');
        $this->patchJson($path, $this->correction(true, $entry, ['category_id' => (int) $otherCategory]), $this->key())->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->patchJson($path, $this->correction(true, $entry), $this->key())->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_sale_date_cannot_be_moved_after_an_existing_receipt(): void
    {
        $entry = $this->entry(true);
        $date = now('America/Sao_Paulo')->subDays(3)->toDateString();
        $entry = $this->patchJson($this->path(true, $entry), $this->correction(true, $entry, ['sale_date' => $date]), $this->key())->assertOk()->json('data');
        $entry = $this->as($this->adminA, $this->orgA)->postJson('/api/v1/receivables/'.$entry['receivable']['id'].'/receipts', [
            'amount' => '10.00', 'method' => 'PIX', 'received_at' => now()->subDays(2)->utc()->toIso8601String(),
        ], $this->key())->assertCreated()->json('data');
        $this->patchJson($this->path(true, $entry), $this->correction(true, $entry, ['sale_date' => now('America/Sao_Paulo')->toDateString()]), $this->key())->assertUnprocessable()->assertJsonValidationErrors('sale_date');
    }

    public function test_audit_failure_rolls_back_correction_deletion_version_and_idempotency_claim(): void
    {
        foreach ([true, false] as $revenue) {
            $entry = $this->entry($revenue);
            $original = app(AuditTrail::class);
            $this->app->instance(AuditTrail::class, new class extends AuditTrail
            {
                public function __construct() {}

                public function record(User $user, string $action, Model $entity, ?array $before, ?array $after): AuditLog
                {
                    throw new RuntimeException('Synthetic audit failure');
                }
            });
            $claims = DB::table('idempotency_keys')->count();
            $path = $this->path($revenue, $entry);
            $this->patchJson($path, $this->correction($revenue, $entry, ['total_amount' => '2000.00']), $this->key())->assertStatus(500);
            $this->deleteJson($path, ['expected_version' => 1], $this->key())->assertStatus(500);
            $this->assertSame($claims, DB::table('idempotency_keys')->count());
            $this->getJson($path)->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.total_amount', '1000.00')
                ->assertJsonPath('data.'.($revenue ? 'receivable' : 'payable').'.expected_method', 'PIX');
            $this->app->instance(AuditTrail::class, $original);
        }
    }
}
