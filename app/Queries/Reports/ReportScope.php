<?php

namespace App\Queries\Reports;

use App\Models\Expense;
use App\Models\Revenue;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Shared frame of the reports (card 11, ADR-033): the authorized company, its civil calendar, one consistent
 * InnoDB snapshot for rows and totals, and the public header every report carries (period, timezone, base,
 * generation instant). Reports only read; they never change financial data.
 */
final class ReportScope
{
    public function __construct(private OrganizationContext $context) {}

    public function organizationId(): int
    {
        return (int) $this->context->organization()->id;
    }

    public function timezone(): string
    {
        return $this->context->organization()->timezone;
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    /** UTC bound (DATETIME(6) literal) of the start of a civil day in the company timezone. */
    public function dayStartsAt(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone())->utc()->format('Y-m-d H:i:s.u');
    }

    /** UTC bound of the end (exclusive) of a civil day: the start of the next local day. */
    public function dayEndsBefore(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->timezone())->addDay()->startOfDay()->utc()->format('Y-m-d H:i:s.u');
    }

    /** Local civil date of a stored UTC instant. */
    public function localDate(string $instant): string
    {
        return CarbonImmutable::parse($instant, 'UTC')->setTimezone($this->timezone())->toDateString();
    }

    public static function iso(string $instant): string
    {
        return CarbonImmutable::parse($instant, 'UTC')->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Rows, counts and totals of a report come from the same snapshot, so a settlement registered meanwhile
     * cannot appear in the page and be missing from the totals. Same pinning as the dashboard.
     */
    public function read(Closure $read): mixed
    {
        if (DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return DB::transaction($read);
    }

    /**
     * Whether the company registered any revenue or expense at all, so the interface can tell "nothing
     * registered yet" from "no movement in this period". Deleted entries do not count.
     */
    public function hasEntries(): bool
    {
        return Revenue::owned()->exists() || Expense::owned()->exists();
    }

    /** Common header: what the numbers cover and when they were read. */
    public function header(array $period, string $basis): array
    {
        return [
            'period' => [...$period, 'timezone' => $this->timezone()],
            'basis' => $basis,
            'as_of' => $this->now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'today' => $this->now()->toDateString(),
            'has_entries' => $this->hasEntries(),
        ];
    }

    /** Page metadata in the same shape as the paginated lists. */
    public static function meta(int $page, int $perPage, int $total): array
    {
        return ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / $perPage)), 'per_page' => $perPage, 'total' => $total];
    }
}
