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
use RoundlyConsulting\Credits\Exceptions\InvalidCreditParentException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\CreditParents;
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
 *
 * On an owner with credit parents the hold must fit every level: the whole chain is locked
 * from the top down, each level's available balance is checked (the owner's first, so a
 * refusal names the nearest owner that blocks), and one hold is written per level — the one
 * returned, and a linked hold on each ancestor that points back at it. CreditsHeld fires for
 * every level.
 */
final readonly class HoldCreditsAction
{
    public function __construct(
        private GetCreditsAvailableAction $available,
        private OwnerLock $lock,
        private CreditParents $parents,
    ) {}

    /**
     * @throws CreditHoldsDisabledException
     * @throws InvalidArgumentException when the amount is not positive or the expiry has passed
     * @throws InsufficientCreditsException when the hold would breach the minimum balance of any level
     * @throws InvalidCreditParentException when the owner's parents cannot be walked
     * @throws AmountOverflow
     */
    public function execute(Model&Creditable $creditable, CreditHoldData $data): CreditHold
    {
        CreditHolds::ensureEnabled();

        $bucket = $data->resolvedBucket();
        $expiresAt = CreditHolds::expiry($data, CarbonImmutable::now());
        $chain = $this->parents->chain($creditable);
        /** @var list<array{Model&Creditable, CreditHold, int}> $levels */
        $levels = [];

        LedgerConnection::of($creditable)->transaction(function () use ($creditable, $chain, $data, $bucket, $expiresAt, &$levels): void {
            $levels = [];
            $this->lock->acquireChain($chain);

            $after = [];

            foreach ($chain as $depth => $owner) {
                $available = $this->available->execute($owner, $bucket, lockForUpdate: true);

                OverdraftGuard::check($owner, $available, -$data->amount, $data->allowOverdraft, $creditable);

                $after[$depth] = LedgerBounds::available($available, $data->amount, $bucket);
            }

            $origin = null;

            foreach ($chain as $depth => $owner) {
                $attributes = [
                    'creditable_type' => $owner->getMorphClass(),
                    'creditable_id' => $owner->getKey(),
                    'bucket' => $bucket,
                    'amount' => $data->amount,
                    'status' => CreditHoldStatus::Held,
                    'description' => $data->description,
                    'meta' => $data->meta,
                    'expires_at' => $expiresAt,
                ];

                // Only a chain writes the linking columns: an owner alone needs no migration beyond the holds table.
                if ($origin !== null) {
                    $attributes += ['origin_hold_id' => $origin->getKey(), 'chain_depth' => $depth];
                }

                $hold = CreditHolds::of($owner)->create($attributes);
                $origin ??= $hold;
                $levels[] = [$owner, $hold, $after[$depth]];
            }
        }, OwnerLock::ATTEMPTS);

        foreach ($levels as [$owner, $hold, $after]) {
            CreditsHeld::dispatch($owner, $hold, $after);
        }

        return $levels[0][1];
    }
}
