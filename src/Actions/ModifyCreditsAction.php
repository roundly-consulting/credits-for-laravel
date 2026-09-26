<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;

final class ModifyCreditsAction
{
    public function __construct(
        private readonly GetCreditsBalanceAction $balance,
    ) {}

    public function execute(Model&Creditable $creditable, CreditChangeData $data): Credit
    {
        /** @var Credit $credit */
        $credit = $creditable->getConnection()->transaction(function () use ($creditable, $data): Credit {
            $this->guardAgainstOverdraft($creditable, $data);

            /** @var Credit $credit */
            $credit = $creditable->credits()->create($data->toAttributes());

            return $credit;
        });

        CreditsModified::dispatch(
            $creditable,
            $credit,
            $this->balance->execute($creditable, bucket: $data->resolvedBucket()),
        );

        return $credit;
    }

    private function guardAgainstOverdraft(Model&Creditable $creditable, CreditChangeData $data): void
    {
        if ($data->amount >= 0) {
            return;
        }

        $allowOverdraft = $data->allowOverdraft || (bool) config('credits.allow_overdraft', false);

        if ($allowOverdraft) {
            return;
        }

        $this->lockOwner($creditable);

        $available = $this->balance->execute(
            $creditable,
            lockForUpdate: true,
            bucket: $data->resolvedBucket(),
        );
        $minimum = (int) config('credits.minimum_balance', 0);

        if ($available + $data->amount < $minimum) {
            throw new InsufficientCreditsException(
                creditable: $creditable,
                requested: $data->amount,
                available: $available,
            );
        }
    }

    /**
     * Serialise every guarded debit of one owner on the owner's own row, before the balance
     * is read.
     *
     * Locking the ledger rows alone is not enough on Postgres: under READ COMMITTED a
     * `SELECT … FOR UPDATE` that had to wait for a racing debit answers from the snapshot
     * taken when it *started*, so it never sees the ledger row that debit inserted — and
     * approves a second spend against the old balance. Nor can it lock anything in an empty
     * bucket. Waiting on the owner row first means the balance read that follows starts
     * only after the racing debit committed, and sees its row.
     */
    private function lockOwner(Model&Creditable $creditable): void
    {
        $creditable->newQueryWithoutScopes()
            ->whereKey($creditable->getKey())
            ->lockForUpdate()
            ->first([$creditable->getKeyName()]);
    }
}
