<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\Int64;
use RoundlyConsulting\Credits\Support\LedgerConnection;
use RoundlyConsulting\Credits\Support\OwnerLock;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Append one signed ledger row and dispatch CreditsModified. Every change takes the owner
 * lock first and reads the bucket's balance under it, in one transaction with the write, all
 * on the ledger's connection ({@see LedgerConnection}), so racing changes of one owner
 * serialise on every isolation level: a debit is guarded — unless an overdraft is allowed, it
 * may not take the bucket below `credits.minimum_balance` — and the event carries the exact
 * balance this change produced.
 */
final readonly class ModifyCreditsAction
{
    public function __construct(
        private GetCreditsBalanceAction $balance,
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

            $this->guardAgainstOverdraft($creditable, $data, $available);

            $balance = $this->resultingBalance($data, $available);

            /** @var Credit $credit */
            $credit = $creditable->credits()->create($data->toAttributes());

            return $credit;
        }, OwnerLock::ATTEMPTS);

        CreditsModified::dispatch($creditable, $credit, $balance);

        return $credit;
    }

    /**
     * The bucket's balance after this change, checked before the row is written: a result
     * outside the signed 64-bit ledger is refused, so nothing is written.
     *
     * @throws AmountOverflow
     */
    private function resultingBalance(CreditChangeData $data, int $available): int
    {
        $exact = Int64::add($available, $data->amount);

        return Int64::toInt($exact) ?? throw new AmountOverflow(
            "The credits balance of bucket [{$data->resolvedBucket()}] would be [{$exact}] after this change, which does not fit a 64-bit integer; nothing was written.",
        );
    }

    private function guardAgainstOverdraft(Model&Creditable $creditable, CreditChangeData $data, int $available): void
    {
        if ($data->amount >= 0 || $data->allowOverdraft || Config::boolean('credits.allow_overdraft')) {
            return;
        }

        if ($available + $data->amount < CreditsConfig::minimumBalance()) {
            throw new InsufficientCreditsException(
                creditable: $creditable,
                requested: $data->amount,
                available: $available,
            );
        }
    }
}
