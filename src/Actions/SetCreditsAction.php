<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;

final class SetCreditsAction
{
    public function __construct(
        private readonly ModifyCreditsAction $modify,
        private readonly GetCreditsBalanceAction $balance,
    ) {}

    /**
     * Adjust the balance to an exact amount. Returns null when no change is needed.
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
    }
}
