<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldReleased;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Exceptions\LinkedCreditHoldException;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\CreditParents;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * Free a hold without writing to the ledger. Like a capture, it re-reads the hold under the
 * owner lock and a row lock, so a hold is resolved once: releasing one that was captured,
 * released or expired meanwhile throws. A hold made on an owner with credit parents is
 * released on every level, owners locked from the top down. Dispatches CreditHoldReleased
 * for every level.
 */
final readonly class ReleaseCreditHoldAction
{
    public function __construct(
        private OwnerLock $lock,
        private CreditParents $parents,
    ) {}

    /**
     * @throws CreditHoldNotActiveException
     * @throws LinkedCreditHoldException when the hold is an ancestor's level of another one
     */
    public function execute(CreditHold $hold): CreditHold
    {
        CreditHolds::ensureOrigin($hold);

        $connection = $hold->getConnection();
        /** @var list<CreditHold> $released */
        $released = [];

        $connection->transaction(function () use ($hold, $connection, &$released): void {
            $released = [];
            $linked = CreditHolds::linked($hold);

            CreditHolds::lockLevels($this->lock, $this->parents, $connection, [...$linked, $hold]);

            $now = CarbonImmutable::now();
            $fresh = CreditHolds::reread($hold);

            CreditHolds::ensureActive($fresh, $now);

            foreach ([$fresh, ...$linked] as $i => $level) {
                $level = $i === 0 ? $level : CreditHolds::reread($level);

                if ($level->status !== CreditHoldStatus::Held) {
                    continue;
                }

                $level->forceFill([
                    'status' => CreditHoldStatus::Released,
                    'resolved_at' => CreditHold::storedInstant($now),
                ])->save();

                $released[] = $level;
            }
        }, OwnerLock::ATTEMPTS);

        $hold->setRawAttributes($released[0]->getAttributes(), true);
        $released[0] = $hold;

        foreach ($released as $level) {
            CreditHoldReleased::dispatch($level);
        }

        return $hold;
    }
}
