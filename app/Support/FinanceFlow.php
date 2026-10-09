<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Receivable;
use App\Models\Revenue;

/**
 * Describes one of the two symmetric money flows (revenue → receivables → receipts, expense → payables →
 * payments) so instalments, totals and corrections share one implementation instead of two copies.
 */
final class FinanceFlow
{
    private function __construct(
        public readonly bool $revenue,
        /** @var class-string */
        public readonly string $entryClass,
        /** @var class-string */
        public readonly string $obligationClass,
        /** @var class-string */
        public readonly string $settlementClass,
        public readonly string $entryTable,
        public readonly string $obligationTable,
        public readonly string $settlementTable,
        public readonly string $entryKey,
        public readonly string $obligationKey,
        public readonly string $instant,
        public readonly string $settledStatus,
        public readonly string $noun,
    ) {}

    public static function revenue(): self
    {
        return new self(true, Revenue::class, Receivable::class, Receipt::class, 'revenues', 'receivables', 'receipts',
            'revenue_id', 'receivable_id', 'received_at', 'RECEIVED', 'recebimento');
    }

    public static function expense(): self
    {
        return new self(false, Expense::class, Payable::class, Payment::class, 'expenses', 'payables', 'payments',
            'expense_id', 'payable_id', 'paid_at', 'PAID', 'pagamento');
    }

    public static function for(bool $revenue): self
    {
        return $revenue ? self::revenue() : self::expense();
    }

    /** RECEIVED/PAID when nothing remains, OVERDUE when something remains past the company's day, else PENDING. */
    public function statusFor(string $remaining, string $dueDate, string $today): string
    {
        if (Money::compare($remaining, '0.00') <= 0) {
            return $this->settledStatus;
        }

        return $dueDate < $today ? 'OVERDUE' : 'PENDING';
    }
}
