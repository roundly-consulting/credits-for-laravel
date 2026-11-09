<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
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
 */
final readonly class ModifyCreditsAction
{
    public function __construct(
        private GetCreditsBalanceAction $balance,
        private GetCreditsHeldAction $held,
        private OwnerLock $lock,
    ) {}

    public function execute(Model&Creditable $creditable, CreditChangeData $data): Credit
    {
        $balance = 0;

        /** @var Credit $credit */
        $credit = LedgerConnection::of($creditable)->transaction(function () use ($creditable, $data, &$balance): Credit {
            $this->lock->acquire($creditable);

            $available = $this->balance->execute(
                $creditable,
                lockForUpdate: true,
                bucket: $data->resolvedBucket(),
            );

            // Held credits are not spendable: a guarded debit decides on the balance minus the
            // active holds, read under the same lock. Read only when the guard applies.
            if (OverdraftGuard::applies($data->amount, $data->allowOverdraft)) {
                OverdraftGuard::check(
                    $creditable,
                    LedgerBounds::available($available, $this->held->execute($creditable, $data->resolvedBucket(), lockForUpdate: true), $data->resolvedBucket()),
                    $data->amount,
                    $data->allowOverdraft,
                );
            }

            // Checked before the row is written: a result outside int64 is refused, nothing written.
            $balance = LedgerBounds::balanceAfter($available, $data->amount, $data->resolvedBucket());

            /** @var Credit $credit */
            $credit = $creditable->credits()->create($data->toAttributes());

            return $credit;
        }, OwnerLock::ATTEMPTS);

        CreditsModified::dispatch($creditable, $credit, $balance);

        return $credit;
    }
}
