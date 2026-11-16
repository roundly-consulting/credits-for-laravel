<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Exceptions\InvalidCreditParentException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditLevel;
use RoundlyConsulting\Credits\Support\CreditParents;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Credits\Support\LedgerConnection;
use RoundlyConsulting\Credits\Support\OverdraftGuard;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * Append one signed ledger row and dispatch CreditsModified. Every change takes the owner
 * lock first and reads the bucket's balance under it, in one transaction with the write, all
 * on the ledger's connection ({@see LedgerConnection}), so racing changes of one owner
 * serialise on every isolation level: a debit is guarded — unless an overdraft is allowed, it
 * may not take the bucket's available balance (the balance minus its active holds, when holds
 * are on) below `credits.minimum_balance` — and the event carries the exact balance this
 * change produced.
 *
 * A debit of an owner with credit parents is a debit of its whole chain: the chain is locked
 * from the top down, every level is guarded (the owner's first, so a refusal names the
 * nearest owner that blocks), and one row is written per level, with CreditsModified for each.
 * The owner's row is returned. A grant (a positive amount) stays with the owner. Extra ceilings
 * (`CreditChangeData::$alsoWithin`) are levels of a debit too, with their own parents.
 */
final readonly class ModifyCreditsAction
{
    public function __construct(
        private GetCreditsBalanceAction $balance,
        private GetCreditsHeldAction $held,
        private OwnerLock $lock,
        private CreditParents $parents,
    ) {}

    /**
     * @param  bool  $withinParents  @internal false: this owner only, whatever its parents —
     *                               a reset, or one level of a capture
     *
     * @throws InsufficientCreditsException when a level's balance would breach the minimum
     * @throws InvalidCreditParentException when the owner's parents cannot be walked
     */
    public function execute(Model&Creditable $creditable, CreditChangeData $data, bool $withinParents = true): Credit
    {
        $chain = $withinParents && $data->amount < 0
            ? $this->parents->levels($creditable, $data->alsoWithin)
            : [new CreditLevel($creditable, 0)];
        /** @var list<array{Model&Creditable, Credit, int}> $levels */
        $levels = [];

        LedgerConnection::of($creditable)->transaction(function () use ($creditable, $chain, $data, &$levels): void {
            $levels = [];
            $bucket = $data->resolvedBucket();

            $this->lock->acquireLevels($chain);

            $balances = [];

            foreach ($chain as $depth => $level) {
                $owner = $level->owner;
                $balances[$depth] = $this->balance->execute($owner, lockForUpdate: true, bucket: $bucket);

                // Held credits are not spendable: a guarded debit decides on the balance minus the
                // active holds, read under the same lock. Read only when the guard applies.
                if (OverdraftGuard::applies($data->amount, $data->allowOverdraft)) {
                    OverdraftGuard::check(
                        $owner,
                        LedgerBounds::available($balances[$depth], $this->held->execute($owner, $bucket, lockForUpdate: true), $bucket),
                        $data->amount,
                        $data->allowOverdraft,
                        $creditable,
                        $level->ceiling,
                    );
                }

                // Checked before any row is written: a result outside int64 is refused, nothing written.
                $balances[$depth] = LedgerBounds::balanceAfter($balances[$depth], $data->amount, $bucket);
            }

            foreach ($chain as $depth => $level) {
                $owner = $level->owner;
                /** @var Credit $credit */
                $credit = $owner->credits()->create($data->toAttributes());

                $levels[] = [$owner, $credit, $balances[$depth]];
            }
        }, OwnerLock::ATTEMPTS);

        foreach ($levels as [$owner, $credit, $balance]) {
            CreditsModified::dispatch($owner, $credit, $balance);
        }

        return $levels[0][1];
    }
}
