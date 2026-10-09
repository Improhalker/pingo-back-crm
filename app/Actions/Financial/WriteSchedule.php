<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Support\FinanceFlow;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the obligations of a new revenue/expense (one line or an instalment schedule, ADR-032) and the
 * explicit settlements of the lines that carry one (down payment or "settled at once", ADR-029). Runs inside
 * the caller's transaction; settlements go through RegisterReceipt/RegisterPayment, with their own lock and
 * audit, so a schedule never bypasses the settlement rules. A date never settles anything by itself.
 */
final class WriteSchedule
{
    public function __construct(private RegisterReceipt $receipts, private RegisterPayment $payments) {}

    /**
     * @param  list<array{amount: string, down_payment: bool, due_date: string, expected_method: string, reminder_date: ?string, settlement: ?array}>  $lines
     * @return list<array{id: int, line: array}>
     */
    public function obligations(FinanceFlow $flow, Model $entry, array $lines): array
    {
        $created = [];
        foreach (array_values($lines) as $index => $line) {
            $obligation = new ($flow->obligationClass);
            $obligation->forceFill([
                'organization_id' => $entry->organization_id,
                $flow->entryKey => $entry->id,
                'sequence' => $index + 1,
                'amount' => $line['amount'],
                'due_date' => $line['due_date'],
                'reminder_date' => $line['reminder_date'],
                'is_down_payment' => $line['down_payment'],
                'expected_method' => $line['expected_method'],
            ])->save();
            $created[] = ['id' => $obligation->id, 'line' => $line];
        }

        return $created;
    }

    /** Public audit view of the written lines. */
    public static function audit(array $created): array
    {
        return array_map(fn ($item) => [
            'id' => $item['id'],
            'amount' => $item['line']['amount'],
            'due_date' => $item['line']['due_date'],
            'reminder_date' => $item['line']['reminder_date'],
            'expected_method' => $item['line']['expected_method'],
            'down_payment' => $item['line']['down_payment'],
            'settled_at_creation' => $item['line']['settlement'] !== null,
        ], $created);
    }

    public function settle(FinanceFlow $flow, User $user, array $created): void
    {
        foreach ($created as $item) {
            $settlement = $item['line']['settlement'];
            if ($settlement === null) {
                continue;
            }
            $data = ['amount' => $item['line']['amount'], 'method' => $settlement['method'], $flow->instant => $settlement['instant']];
            $flow->revenue ? ($this->receipts)($user, $item['id'], $data) : ($this->payments)($user, $item['id'], $data);
        }
    }
}
