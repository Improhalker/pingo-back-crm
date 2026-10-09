<?php

namespace Tests\Feature\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

/** Two companies with ADMIN/OPERATOR members and a platform master (no membership). */
trait FinanceFixtures
{
    protected Organization $orgA;

    protected Organization $orgB;

    protected User $adminA;

    protected User $operatorA;

    protected User $adminB;

    protected User $master;

    protected function setUpCompanies(string $timezone = 'America/Sao_Paulo'): void
    {
        $this->orgA = Organization::create(['name' => 'Synthetic A', 'timezone' => $timezone]);
        $this->orgB = Organization::create(['name' => 'Synthetic B', 'timezone' => $timezone]);
        $this->adminA = User::factory()->create();
        $this->operatorA = User::factory()->create();
        $this->adminB = User::factory()->create();
        $this->master = User::factory()->create();
        $this->master->platformAdministrator()->create();
        $this->adminA->organizations()->attach($this->orgA, ['role' => 'ADMIN']);
        $this->operatorA->organizations()->attach($this->orgA, ['role' => 'OPERATOR']);
        $this->adminB->organizations()->attach($this->orgB, ['role' => 'ADMIN']);
    }

    /** Acts as $user inside $organization (the header is a selector; membership is checked server-side). */
    protected function as(User $user, Organization $organization): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'web')->withHeaders(['X-Organization-Id' => (string) $organization->id]);
    }

    protected function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    protected function customer(Organization $organization, string $name = 'Cliente Sintético', ?User $admin = null): string
    {
        return $this->as($admin ?? $this->adminFor($organization), $organization)
            ->postJson('/api/v1/customers', ['name' => $name])->assertCreated()->json('data.id');
    }

    protected function category(Organization $organization, string $type = 'REVENUE', string $name = 'Aulas práticas'): string
    {
        return $this->as($this->adminFor($organization), $organization)
            ->postJson('/api/v1/categories', ['name' => $name, 'type' => $type])->assertCreated()->json('data.id');
    }

    protected function revenuePayload(string $customerId, string $categoryId, array $overrides = []): array
    {
        return [
            'customer_id' => (int) $customerId,
            'category_id' => (int) $categoryId,
            'description' => 'Pacote de 10 aulas',
            'total_amount' => '1000.00',
            'sale_date' => now('America/Sao_Paulo')->toDateString(),
            'due_date' => now('America/Sao_Paulo')->addDays(10)->toDateString(),
            'expected_method' => 'PIX',
            'notes' => null,
            ...$overrides,
        ];
    }

    private function adminFor(Organization $organization): User
    {
        return $organization->is($this->orgA) ? $this->adminA : $this->adminB;
    }
}
