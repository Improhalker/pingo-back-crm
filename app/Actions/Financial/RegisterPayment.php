<?php

namespace App\Actions\Financial;

use App\Models\Expense;
use App\Models\Payable;
use App\Models\Payment;
use App\Models\User;
use App\Support\AuditTrail;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers one total or partial settlement. The obligation row is locked (SELECT ... FOR UPDATE) and the
 * remaining amount is recalculated from persisted payments inside the transaction, so two requests cannot
 * consume the same remaining amount. The payment and its audit entry commit together or not at all.
 */
final class RegisterPayment
{
    public function __construct(private OrganizationContext $context, private AuditTrail $audit) {}

    public function __invoke(User $user, int $payableId, array $data): Payment
    {
        return DB::transaction(function () use ($user, $payableId, $data) {
            $payable = Payable::owned()->whereKey($payableId)->lockForUpdate()->firstOrFail();
            $entry = Expense::owned()->whereKey($payable->expense_id)->lockForUpdate()->firstOrFail();
            $paid = $payable->paidTotal();
            $remaining = Money::sub($payable->amount, $paid);

            if (Money::compare($remaining, '0.00') <= 0) {
                throw ValidationException::withMessages(['amount' => ['Esta despesa já está quitada.']]);
            }
            if (Money::compare($data['amount'], $remaining) > 0) {
                throw ValidationException::withMessages(['amount' => ['O valor excede o restante a pagar de R$ '.self::display($remaining).'.']]);
            }

            $payment = new Payment;
            $payment->forceFill([
                'organization_id' => $this->context->organization()->id,
                'payable_id' => $payable->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'paid_at' => CarbonImmutable::parse($data['paid_at'])->utc(),
                'created_by' => $user->id,
            ])->save();

            $newPaid = Money::add($paid, $data['amount']);
            $this->audit->record($user, 'payment.created', $payment, [
                'payable' => ['id' => $payable->id, 'paid_amount' => $paid, 'remaining_amount' => $remaining],
            ], [
                'payment' => ['amount' => $data['amount'], 'method' => $data['method'], 'paid_at' => $data['paid_at']],
                'payable' => ['id' => $payable->id, 'paid_amount' => $newPaid, 'remaining_amount' => Money::sub($payable->amount, $newPaid)],
            ]);

            $entry->version++;
            $entry->save();

            return $payment;
        });
    }

    /** "600.00" → "600,00" for messages (thousands grouped with dots). */
    private static function display(string $amount): string
    {
        [$integer, $decimals] = explode('.', Money::normalize($amount));

        return number_format((int) $integer, 0, ',', '.').','.$decimals;
    }
}
