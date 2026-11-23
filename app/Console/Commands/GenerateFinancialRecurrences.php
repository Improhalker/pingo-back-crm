<?php

namespace App\Console\Commands;

use App\Actions\Financial\GenerateRecurringEntries;
use App\Models\FinancialRecurrence;
use App\Models\Organization;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class GenerateFinancialRecurrences extends Command
{
    protected $signature = 'finance:generate-recurrences';

    protected $description = 'Create pending monthly revenue and expense occurrences for active companies';

    public function handle(OrganizationContext $context, GenerateRecurringEntries $generate): int
    {
        $created = $blocked = 0;
        // Local dates never exceed UTC plus one day. Each locked series rechecks its precise local horizon.
        $upper = CarbonImmutable::now('UTC')->addDay()->startOfMonth()->addMonth()->toDateString();
        foreach (FinancialRecurrence::query()->where('status', 'ACTIVE')->where('next_month', '<=', $upper)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereColumn('next_month', '<=', 'ends_on'))
            ->select('id', 'organization_id')->lazyById() as $rule) {
            $organization = Organization::query()->whereKey($rule->organization_id)->whereNull('suspended_at')->first();
            if (! $organization) {
                continue;
            }

            $context->set($organization);
            try {
                $created += $generate->run($rule->id);
            } catch (ValidationException $exception) {
                // An archived category/source blocks this series. It must not stop other organizations.
                $blocked++;
                Log::warning('finance.recurrence_blocked', [
                    'organization_id' => $organization->id, 'series_id' => $rule->id, 'fields' => array_keys($exception->errors()),
                ]);
            } finally {
                $context->clear();
            }
        }

        $this->info("Ocorrências geradas: {$created}. Séries com cadastro bloqueado: {$blocked}.");

        return self::SUCCESS;
    }
}
