<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\FinanceFixtures;
use Tests\TestCase;

/** Topic 01: customers and categories per company. */
class CatalogTest extends TestCase
{
    use FinanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCompanies();
    }

    public function test_session_and_an_authorized_organization_context_are_required(): void
    {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        // ADR-028: without a selector the server opens the account's single company.
        $this->actingAs($this->adminA, 'web')->getJson('/api/v1/customers')->assertOk();
        $this->as($this->adminA, $this->orgB)->getJson('/api/v1/customers')->assertNotFound();
        // The master has no company membership: explicit denial, no context (ADR-026/028).
        $this->as($this->master, $this->orgA)->getJson('/api/v1/customers')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
        $this->as($this->master, $this->orgA)->getJson('/api/v1/revenues')->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_NONE');
    }

    public function test_admin_creates_edits_searches_paginates_and_archives_customers(): void
    {
        $api = $this->as($this->adminA, $this->orgA);
        $ana = $api->postJson('/api/v1/customers', ['name' => 'Ana Souza', 'phone' => '(11) 98888-7777', 'email' => 'ana@exemplo.test', 'document' => '123.456.789-00', 'notes' => 'Prefere Pix'])
            ->assertCreated()->assertJsonPath('data.archived', false)->json('data.id');
        foreach (['Bruno Lima', 'Carla Dias'] as $name) {
            $api->postJson('/api/v1/customers', ['name' => $name])->assertCreated();
        }

        $api->getJson('/api/v1/customers?per_page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.name', 'Ana Souza')->assertJsonCount(2, 'data');
        $api->getJson('/api/v1/customers?search=carla')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Carla Dias');
        $api->getJson('/api/v1/customers?search=ana@exemplo')->assertOk()->assertJsonPath('meta.total', 1);

        $api->patchJson("/api/v1/customers/{$ana}", ['name' => 'Ana Souza Lima', 'phone' => null, 'email' => 'ana@exemplo.test', 'document' => null, 'notes' => null])
            ->assertOk()->assertJsonPath('data.name', 'Ana Souza Lima')->assertJsonPath('data.phone', null);

        $api->postJson("/api/v1/customers/{$ana}/archive")->assertOk()->assertJsonPath('data.archived', true);
        $api->getJson('/api/v1/customers')->assertJsonPath('meta.total', 2);
        $api->getJson('/api/v1/customers?status=archived')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ana);
        $api->postJson("/api/v1/customers/{$ana}/unarchive")->assertOk()->assertJsonPath('data.archived', false);
        $this->assertDatabaseHas('customers', ['id' => $ana, 'organization_id' => $this->orgA->id, 'name' => 'Ana Souza Lima']);
    }

    public function test_customer_fields_are_validated_and_server_owned_fields_rejected(): void
    {
        $api = $this->as($this->adminA, $this->orgA);
        $api->postJson('/api/v1/customers', ['name' => ' '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $api->postJson('/api/v1/customers', ['name' => 'Ok', 'email' => 'not-an-email'])->assertJsonValidationErrors('email');
        $api->postJson('/api/v1/customers', ['name' => 'Ok', 'phone' => 'abc'])->assertJsonValidationErrors('phone');
        $api->postJson('/api/v1/customers', ['name' => str_repeat('a', 121)])->assertJsonValidationErrors('name');
        $api->postJson('/api/v1/customers', ['name' => 'Ok', 'organization_id' => $this->orgB->id])->assertJsonValidationErrors('organization_id');
        $api->postJson('/api/v1/customers', ['name' => 'Ok', 'archived_at' => now()->toIso8601String()])->assertJsonValidationErrors('archived_at');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_operator_consults_but_does_not_change_the_registry(): void
    {
        $customer = $this->customer($this->orgA);
        $category = $this->category($this->orgA);
        $operator = $this->as($this->operatorA, $this->orgA);
        $operator->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.total', 1);
        $operator->getJson("/api/v1/customers/{$customer}")->assertOk();
        $operator->getJson('/api/v1/categories')->assertOk()->assertJsonPath('meta.total', 1);
        $operator->postJson('/api/v1/customers', ['name' => 'Novo'])->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_ROLE_FORBIDDEN');
        $operator->patchJson("/api/v1/customers/{$customer}", ['name' => 'Outro'])->assertForbidden();
        $operator->postJson("/api/v1/customers/{$customer}/archive")->assertForbidden();
        $operator->postJson('/api/v1/categories', ['name' => 'Nova', 'type' => 'REVENUE'])->assertForbidden()->assertJsonPath('code', 'ORGANIZATION_ROLE_FORBIDDEN');
        $operator->postJson("/api/v1/categories/{$category}/archive")->assertForbidden();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseMissing('customers', ['name' => 'Outro']);
    }

    public function test_another_company_cannot_list_read_or_change_these_ids(): void
    {
        $customer = $this->customer($this->orgA);
        $category = $this->category($this->orgA);
        $intruder = $this->as($this->adminB, $this->orgB);
        $intruder->getJson('/api/v1/customers')->assertOk()->assertJsonPath('meta.total', 0);
        $intruder->getJson('/api/v1/categories')->assertOk()->assertJsonPath('meta.total', 0);
        $intruder->getJson("/api/v1/customers/{$customer}")->assertNotFound();
        $intruder->patchJson("/api/v1/customers/{$customer}", ['name' => 'Invadido'])->assertNotFound();
        $intruder->postJson("/api/v1/customers/{$customer}/archive")->assertNotFound();
        $intruder->getJson("/api/v1/categories/{$category}")->assertNotFound();
        $intruder->patchJson("/api/v1/categories/{$category}", ['name' => 'Invadida'])->assertNotFound();
        $intruder->postJson("/api/v1/categories/{$category}/archive")->assertNotFound();
        $this->assertDatabaseHas('customers', ['id' => $customer, 'name' => 'Cliente Sintético', 'archived_at' => null]);
        $this->assertDatabaseHas('categories', ['id' => $category, 'name' => 'Aulas práticas', 'archived_at' => null]);
    }

    public function test_categories_have_a_fixed_type_unique_names_and_filters(): void
    {
        $api = $this->as($this->adminA, $this->orgA);
        $revenue = $this->category($this->orgA, 'REVENUE', 'Aulas');
        $this->category($this->orgA, 'EXPENSE', 'Aulas');
        $api->postJson('/api/v1/categories', ['name' => 'aulas', 'type' => 'REVENUE'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $api->postJson('/api/v1/categories', ['name' => 'Taxas', 'type' => 'OTHER'])->assertJsonValidationErrors('type');
        $api->patchJson("/api/v1/categories/{$revenue}", ['name' => 'Aulas teóricas', 'type' => 'EXPENSE'])->assertJsonValidationErrors('type');
        $api->patchJson("/api/v1/categories/{$revenue}", ['name' => 'Aulas teóricas'])->assertOk()->assertJsonPath('data.type', 'REVENUE');
        $api->getJson('/api/v1/categories?type=EXPENSE')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.type', 'EXPENSE');
        // Another company may use the same name.
        $this->category($this->orgB, 'REVENUE', 'Aulas teóricas');
    }

    public function test_archived_category_and_customer_stay_on_history_but_reject_new_entries(): void
    {
        $customer = $this->customer($this->orgA);
        $category = $this->category($this->orgA);
        $api = $this->as($this->adminA, $this->orgA);
        $revenue = $api->postJson('/api/v1/revenues', $this->revenuePayload($customer, $category), $this->key())->assertCreated()->json('data.id');

        $api->postJson("/api/v1/categories/{$category}/archive")->assertOk();
        $api->postJson("/api/v1/customers/{$customer}/archive")->assertOk();
        $api->getJson("/api/v1/revenues/{$revenue}")->assertOk()
            ->assertJsonPath('data.category.name', 'Aulas práticas')->assertJsonPath('data.category.archived', true)
            ->assertJsonPath('data.customer.archived', true);
        $api->postJson('/api/v1/revenues', $this->revenuePayload($customer, $category), $this->key())
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'category_id']);
    }

    public function test_database_rejects_references_across_companies(): void
    {
        $customerB = $this->customer($this->orgB);
        $categoryA = $this->category($this->orgA);
        $this->expectException(QueryException::class);
        // Composite (organization_id, customer_id) foreign key: a revenue of A cannot point to a customer of B.
        DB::table('revenues')->insert([
            'organization_id' => $this->orgA->id, 'customer_id' => $customerB, 'category_id' => $categoryA,
            'description' => 'Cruzada', 'total_amount' => '10.00', 'sale_date' => '2026-10-06', 'created_by' => $this->adminA->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_company_with_registry_data_cannot_be_deleted_by_the_platform(): void
    {
        $this->customer($this->orgA);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->master, 'web')
            ->deleteJson("/api/v1/platform/organizations/{$this->orgA->id}", ['confirmation_name' => 'Synthetic A'])
            ->assertStatus(409)->assertJsonPath('code', 'PLATFORM_ORGANIZATION_HAS_DATA');
        $this->assertDatabaseHas('organizations', ['id' => $this->orgA->id]);
    }
}
