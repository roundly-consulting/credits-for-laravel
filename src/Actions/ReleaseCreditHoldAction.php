<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldReleased;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * Free a hold without writing to the ledger. Like a capture, it re-reads the hold under the
 * owner lock and a row lock, so a hold is resolved once: releasing one that was captured,
 * released or expired meanwhile throws. Dispatches CreditHoldReleased.
 */
final readonly class ReleaseCreditHoldAction
{
    public function __construct(
        private OwnerLock $lock,
    ) {}

    /**
     * @throws CreditHoldNotActiveException
     */
    public function execute(CreditHold $hold): CreditHold
    {
        $connection = $hold->getConnection();

        /** @var CreditHold $fresh */
        $fresh = $connection->transaction(function () use ($hold, $connection): CreditHold {
            $this->lock->acquireFor($connection, $hold->creditable_type, $hold->creditable_id);

            $now = CarbonImmutable::now();
            $fresh = CreditHolds::reread($hold);

            CreditHolds::ensureActive($fresh, $now);

            $fresh->forceFill([
                'status' => CreditHoldStatus::Released,
                'resolved_at' => CreditHold::storedInstant($now),
            ])->save();

            return $fresh;
        }, OwnerLock::ATTEMPTS);

        $hold->setRawAttributes($fresh->getAttributes(), true);

        CreditHoldReleased::dispatch($hold);

        return $hold;
    }
}
