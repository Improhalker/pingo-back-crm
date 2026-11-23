<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\FinanceFlow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class FinancialRecurrence extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'next_month' => 'immutable_date',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(FinancialRecurrenceRate::class);
    }

    public function latestRate(): HasOne
    {
        return $this->hasOne(FinancialRecurrenceRate::class)->latestOfMany();
    }

    public function flow(): FinanceFlow
    {
        return FinanceFlow::for($this->kind === 'REVENUE');
    }

    public function dueIn(CarbonImmutable $month): CarbonImmutable
    {
        return $month->startOfMonth()->addDays(min($this->due_day, $month->daysInMonth) - 1);
    }

    public function amountIn(CarbonImmutable $month): string
    {
        return (string) $this->rates()->where('effective_month', '<=', $month->toDateString())->latest('id')->firstOrFail()->amount;
    }
}
