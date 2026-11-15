<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditParents;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Credits\Support\LedgerConnection;
use RoundlyConsulting\Credits\Support\OwnerLock;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * Adjust a bucket's balance to an exact amount with one delta row.
 */
final readonly class SetCreditsAction
{
    public function __construct(
        private ModifyCreditsAction $modify,
        private GetCreditsBalanceAction $balance,
        private OwnerLock $lock,
        private CreditParents $parents,
    ) {}

    /**
     * Returns null when no change is needed. The owner lock is taken before the balance is
     * read — under a row lock, so MySQL's REPEATABLE READ answers it from the latest commit
     * rather than the snapshot — inside one transaction with the write, so two racing calls
     * serialise on every isolation level: the second computes its delta from the balance the
     * first left behind. A delta outside the signed 64-bit ledger throws AmountOverflow, and
     * nothing is written.
     *
     * Lowering an owner that has credit parents writes the delta as a debit of the whole chain
     * (see {@see ModifyCreditsAction}); raising it stays with the owner.
     *
     * @param  array<string, mixed>|null  $meta
     * @param  bool  $withinParents  @internal false: this owner only — a reset
     *
     * @throws AmountOverflow
     * @throws InsufficientCreditsException when lowering it would breach the minimum of a level
     */
    public function execute(
        Model&Creditable $creditable,
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
        bool $withinParents = true,
    ): ?Credit {
        // The whole chain is locked before the balance is read, top down like every chain
        // write: the delta's sign is not known yet, and taking an ancestor after the owner
        // would invert the order a racing hold takes them in.
        $chain = $withinParents ? $this->parents->chain($creditable) : [$creditable];

        /** @var Credit|null $credit */
        $credit = LedgerConnection::of($creditable)->transaction(function () use ($creditable, $chain, $amount, $description, $meta, $allowOverdraft, $bucket, $withinParents): ?Credit {
            $this->lock->acquireChain($chain);

            $delta = LedgerBounds::delta(
                $amount,
                $this->balance->execute($creditable, lockForUpdate: true, bucket: $bucket),
                $bucket ?? CreditsConfig::defaultBucket(),
            );

            if ($delta === 0) {
                return null;
            }

            return $this->modify->execute($creditable, new CreditChangeData(
                amount: $delta,
                description: $description,
                meta: $meta,
                allowOverdraft: $allowOverdraft,
                bucket: $bucket,
            ), $withinParents);
        }, OwnerLock::ATTEMPTS);

        return $credit;
    }
}
