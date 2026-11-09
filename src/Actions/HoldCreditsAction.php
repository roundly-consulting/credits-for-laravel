<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\Credits\DataTransferObjects\CreditHoldData;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditsHeld;
use RoundlyConsulting\Credits\Exceptions\CreditHoldsDisabledException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Credits\Support\LedgerConnection;
use RoundlyConsulting\Credits\Support\OverdraftGuard;
use RoundlyConsulting\Credits\Support\OwnerLock;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * Reserve part of a bucket's available balance. Decided exactly like a debit — under the
 * owner lock, against the available balance read under row locks, in one transaction with
 * the write — so two racing holds (or a hold and a debit) of one owner can never reserve
 * more than the bucket can spend. Writes no ledger row; dispatches CreditsHeld.
 */
final readonly class HoldCreditsAction
{
    public function __construct(
        private GetCreditsAvailableAction $available,
        private OwnerLock $lock,
    ) {}

    /**
     * @throws CreditHoldsDisabledException
     * @throws InvalidArgumentException when the amount is not positive or the expiry has passed
     * @throws InsufficientCreditsException when the hold would breach the minimum balance
     * @throws AmountOverflow
     */
    public function execute(Model&Creditable $creditable, CreditHoldData $data): CreditHold
    {
        CreditHolds::ensureEnabled();

        $bucket = $data->resolvedBucket();
        $expiresAt = CreditHolds::expiry($data, CarbonImmutable::now());
        $after = 0;

        /** @var CreditHold $hold */
        $hold = LedgerConnection::of($creditable)->transaction(function () use ($creditable, $data, $bucket, $expiresAt, &$after): CreditHold {
            $this->lock->acquire($creditable);

            $available = $this->available->execute($creditable, $bucket, lockForUpdate: true);

            OverdraftGuard::check($creditable, $available, -$data->amount, $data->allowOverdraft);

            $after = LedgerBounds::available($available, $data->amount, $bucket);

            return CreditHolds::of($creditable)->create([
                'creditable_type' => $creditable->getMorphClass(),
                'creditable_id' => $creditable->getKey(),
                'bucket' => $bucket,
                'amount' => $data->amount,
                'status' => CreditHoldStatus::Held,
                'description' => $data->description,
                'meta' => $data->meta,
                'expires_at' => $expiresAt,
            ]);
        }, OwnerLock::ATTEMPTS);

        CreditsHeld::dispatch($creditable, $hold, $after);

        return $hold;
    }
}
