<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Expected obligation of an expense. Its paid/remaining amounts derive only from persisted payments. */
class Payable extends Model
{
    use BelongsToOrganization;

    public const METHODS = ['CASH', 'PIX', 'BOLETO', 'CARD', 'TRANSFER', 'OTHER'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'immutable_date', 'reminder_date' => 'immutable_date', 'is_down_payment' => 'boolean'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_at')->orderBy('id');
    }

    /** Sum of the payments in the database (exact DECIMAL sum), as a canonical string. */
    public function paidTotal(): string
    {
        return Money::normalize((string) ($this->payments()->effective()->toBase()->sum('amount') ?: '0.00'));
    }

    /**
     * Status derived from values and dates: PAID when nothing remains; OVERDUE when something remains and
     * the due date is before today in the organization timezone (due today is not overdue); otherwise PENDING.
     */
    public static function statusFor(string $remaining, CarbonImmutable $dueDate, string $timezone): string
    {
        if (Money::compare($remaining, '0.00') <= 0) {
            return 'PAID';
        }
        $today = CarbonImmutable::now($timezone)->toDateString();

        return $dueDate->toDateString() < $today ? 'OVERDUE' : 'PENDING';
    }
}
