<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldCaptured;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\LedgerConnection;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * Turn a hold into a ledger debit — of the whole hold, or of part of it with the rest
 * released. Under the owner lock the hold is re-read under a row lock and must still be
 * active, so a hold is captured at most once, and never after it expired or was released;
 * the hold's update and the debit commit together.
 *
 * The debit is not guarded against the minimum balance: the hold already reserved it.
 * Dispatches CreditHoldCaptured (and CreditsModified for the debit).
 */
final readonly class CaptureCreditHoldAction
{
    public function __construct(
        private ModifyCreditsAction $modify,
        private OwnerLock $lock,
    ) {}

    /**
     * @param  array<string, mixed>|null  $meta  The debit's meta; the hold's when null.
     *
     * @throws CreditHoldNotActiveException
     * @throws InvalidArgumentException when the amount is outside 1..the held amount, or the owner is gone
     */
    public function execute(CreditHold $hold, ?int $amount = null, ?string $description = null, ?array $meta = null): Credit
    {
        $owner = CreditHolds::owner($hold);
        $captured = CreditHolds::captureAmount($hold, $amount);

        /** @var array{CreditHold, Credit} $result */
        $result = LedgerConnection::of($owner)->transaction(function () use ($hold, $owner, $captured, $description, $meta): array {
            $this->lock->acquire($owner);

            $now = CarbonImmutable::now();
            $fresh = CreditHolds::reread($hold);

            CreditHolds::ensureActive($fresh, $now);

            $credit = $this->modify->execute($owner, new CreditChangeData(
                amount: -$captured,
                description: $description ?? $fresh->description,
                meta: $meta ?? $fresh->meta,
                allowOverdraft: true,
                bucket: $fresh->bucket,
            ));

            $fresh->forceFill([
                'status' => CreditHoldStatus::Captured,
                'captured_amount' => $captured,
                'credit_id' => $credit->getKey(),
                'resolved_at' => CreditHold::storedInstant($now),
            ])->save();

            return [$fresh, $credit];
        }, OwnerLock::ATTEMPTS);

        [$fresh, $credit] = $result;

        $hold->setRawAttributes($fresh->getAttributes(), true);

        CreditHoldCaptured::dispatch($owner, $hold, $credit, $hold->amount - $captured);

        return $credit;
    }
}
