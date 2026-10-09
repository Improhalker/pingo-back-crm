<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Expected obligation of a revenue. Its received/remaining amounts derive only from persisted receipts. */
class Receivable extends Model
{
    use BelongsToOrganization;

    public const METHODS = ['CASH', 'PIX', 'BOLETO', 'CARD', 'TRANSFER', 'OTHER'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'due_date' => 'immutable_date', 'reminder_date' => 'immutable_date', 'is_down_payment' => 'boolean'];
    }

    public function revenue(): BelongsTo
    {
        return $this->belongsTo(Revenue::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->orderBy('received_at')->orderBy('id');
    }

    /** Sum of the effective receipts in the database (exact DECIMAL sum), as a canonical string. */
    public function receivedTotal(): string
    {
        return Money::normalize((string) ($this->receipts()->effective()->toBase()->sum('amount') ?: '0.00'));
    }

    /**
     * Status derived from values and dates: RECEIVED when nothing remains; OVERDUE when something remains and
     * the due date is before today in the organization timezone (due today is not overdue); otherwise PENDING.
     */
    public static function statusFor(string $remaining, CarbonImmutable $dueDate, string $timezone): string
    {
        if (Money::compare($remaining, '0.00') <= 0) {
            return 'RECEIVED';
        }
        $today = CarbonImmutable::now($timezone)->toDateString();

        return $dueDate->toDateString() < $today ? 'OVERDUE' : 'PENDING';
    }
}
