<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * Adjust a bucket's balance to an exact amount with one delta row.
 */
final readonly class SetCreditsAction
{
    public function __construct(
        private ModifyCreditsAction $modify,
        private GetCreditsBalanceAction $balance,
        private OwnerLock $lock,
    ) {}

    /**
     * Returns null when no change is needed. The owner row is locked before the balance is
     * read, inside one transaction with the write, so two racing calls serialise: the second
     * computes its delta from the balance the first left behind.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function execute(
        Model&Creditable $creditable,
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
    ): ?Credit {
        /** @var Credit|null $credit */
        $credit = $creditable->getConnection()->transaction(function () use ($creditable, $amount, $description, $meta, $allowOverdraft, $bucket): ?Credit {
            $this->lock->acquire($creditable);

            $delta = $amount - $this->balance->execute($creditable, bucket: $bucket);

            if ($delta === 0) {
                return null;
            }

            return $this->modify->execute($creditable, new CreditChangeData(
                amount: $delta,
                description: $description,
                meta: $meta,
                allowOverdraft: $allowOverdraft,
                bucket: $bucket,
            ));
        });

        return $credit;
    }
}
