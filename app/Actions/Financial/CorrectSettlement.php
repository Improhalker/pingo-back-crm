<?php

namespace App\Actions\Financial;

use App\Models\User;
use App\Support\AuditTrail;
use App\Support\FinanceFlow;
use App\Support\Money;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a receipt/payment entered with the wrong amount, instant or method (card 09, ADR-031).
 *
 * A correction is a fix of a typing error, not a refund: the original row is kept untouched as evidence and
 * marked `superseded_at`; a replacement row linked by `corrects_id` carries the right values, the reason, the
 * author and the instant of the correction. Totals, statuses and the dashboard use the replacement, so the
 * cash reflects the corrected values in the corrected period. Locks follow the settlement order (obligation,
 * entry, row); the reviewed entry version detects concurrent edits or settlements.
 */
final class CorrectSettlement
{
    public function __construct(private AuditTrail $audit, private OrganizationContext $context) {}

    public function __invoke(FinanceFlow $flow, User $user, int $settlementId, array $data): Model
    {
        return DB::transaction(function () use ($flow, $user, $settlementId, $data) {
            $settlementClass = $flow->settlementClass;
            $obligationId = $settlementClass::owned()->whereKey($settlementId)->value($flow->obligationKey);
            abort_if($obligationId === null, 404);
            $obligation = ($flow->obligationClass)::owned()->whereKey($obligationId)->lockForUpdate()->firstOrFail();
            $entry = ($flow->entryClass)::owned()->withTrashed()->whereKey($obligation->{$flow->entryKey})->lockForUpdate()->firstOrFail();
            abort_if($entry->trashed(), 404);
            if ((int) $entry->version !== $data['expected_version']) {
                throw self::conflict('FINANCIAL_ENTRY_CHANGED', 'Este lançamento mudou enquanto você revisava a correção. Recarregue os dados e confira antes de tentar novamente.');
            }
            $original = $settlementClass::owned()->whereKey($settlementId)->lockForUpdate()->firstOrFail();
            if ($original->isSuperseded()) {
                throw self::conflict('SETTLEMENT_ALREADY_CORRECTED', 'Esta baixa já foi corrigida. Recarregue para ver a versão atual.');
            }

            $instant = CarbonImmutable::parse($data['instant'])->utc();
            $originalInstant = $original->{$flow->instant}->utc();
            if (Money::compare($data['amount'], (string) $original->amount) === 0 && $data['method'] === $original->method
                && $instant->format('Y-m-d H:i:s') === $originalInstant->format('Y-m-d H:i:s')) {
                throw ValidationException::withMessages(['amount' => ['Nada mudou: altere o valor, a data/hora ou a forma para corrigir.']]);
            }

            $settledBefore = $flow->revenue ? $obligation->receivedTotal() : $obligation->paidTotal();
            $settledByOthers = Money::sub($settledBefore, (string) $original->amount);
            $limit = Money::sub((string) $obligation->amount, $settledByOthers);
            if (Money::compare($data['amount'], $limit) > 0) {
                throw ValidationException::withMessages(['amount' => ['O valor corrigido não pode passar de R$ '.Money::display($limit).', o que falta desta parcela somado a esta baixa.']]);
            }

            $replacement = new $settlementClass;
            $replacement->forceFill([
                'organization_id' => $this->context->organization()->id,
                $flow->obligationKey => $obligation->id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                $flow->instant => $instant,
                'created_by' => $user->id,
                'corrects_id' => $original->id,
                'correction_reason' => $data['reason'],
            ])->save();
            $original->forceFill(['superseded_at' => now('UTC')])->save();

            $settledAfter = Money::add($settledByOthers, $data['amount']);
            $noun = $flow->revenue ? 'receipt' : 'payment';
            $this->audit->record($user, "{$noun}.corrected", $replacement, [
                $noun => ['id' => $original->id, 'amount' => Money::normalize((string) $original->amount), 'method' => $original->method, $flow->instant => $originalInstant->toIso8601String()],
                'obligation' => ['id' => $obligation->id, 'settled_amount' => $settledBefore, 'remaining_amount' => Money::sub((string) $obligation->amount, $settledBefore)],
            ], [
                $noun => ['id' => $replacement->id, 'corrects_id' => $original->id, 'amount' => $data['amount'], 'method' => $data['method'], $flow->instant => $instant->toIso8601String()],
                'obligation' => ['id' => $obligation->id, 'settled_amount' => $settledAfter, 'remaining_amount' => Money::sub((string) $obligation->amount, $settledAfter)],
                'reason' => $data['reason'],
            ]);

            $entry->version++;
            $entry->save();

            return $replacement;
        }, 3);
    }

    private static function conflict(string $code, string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'request_id' => request()?->attributes->get('request_id'),
        ], 409));
    }
}
