<?php

namespace App\Actions\Financial;

use App\Models\Receipt;
use App\Models\Receivable;
use App\Models\Revenue;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers one total or partial settlement. The obligation row is locked (SELECT ... FOR UPDATE) and the
 * remaining amount is recalculated from persisted receipts inside the transaction, so two requests cannot
 * consume the same remaining amount. The receipt and its audit entry commit together or not at all.
 */
final class RegisterReceipt
{
    public function __construct(private OrganizationContext $context, private AuditTrail $audit) {}

    public function __invoke(User $user, int $receivableId, array $data): Receipt
    {
        return DB::transaction(function () use ($user, $receivableId, $data) {
            $receivable = Receivable::owned()->whereKey($receivableId)->lockForUpdate()->firstOrFail();
            $entry = Revenue::owned()->whereKey($receivable->revenue_id)->lockForUpdate()->firstOrFail();
            $received = $receivable->receivedTotal();
            $remaining = Money::sub($receivable->amount, $received);

            if (Money::compare($remaining, '0.00') <= 0) {
                throw ValidationException::withMessages(['amount' => ['Este recebimento já está quitado.']]);
            }
            if (Money::compare($data['amount'], $remaining) > 0) {
                throw ValidationException::withMessages(['amount' => ['O valor excede o restante a receber de R$ '.self::display($remaining).'.']]);
            }

            $receipt = new Receipt;
            $receipt->forceFill([
                'organization_id' => $this->context->organization()->id,
                'receivable_id' => $receivable->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'received_at' => CarbonImmutable::parse($data['received_at'])->utc(),
                'created_by' => $user->id,
            ])->save();

            $newReceived = Money::add($received, $data['amount']);
            $this->audit->record($user, 'receipt.created', $receipt, [
                'receivable' => ['id' => $receivable->id, 'received_amount' => $received, 'remaining_amount' => $remaining],
            ], [
                'receipt' => ['amount' => $data['amount'], 'method' => $data['method'], 'received_at' => $data['received_at']],
                'receivable' => ['id' => $receivable->id, 'received_amount' => $newReceived, 'remaining_amount' => Money::sub($receivable->amount, $newReceived)],
            ]);

            $entry->version++;
            $entry->save();

            return $receipt;
        });
    }

    /** "600.00" → "600,00" for messages (thousands grouped with dots). */
    private static function display(string $amount): string
    {
        [$integer, $decimals] = explode('.', Money::normalize($amount));

        return number_format((int) $integer, 0, ',', '.').','.$decimals;
    }
}
