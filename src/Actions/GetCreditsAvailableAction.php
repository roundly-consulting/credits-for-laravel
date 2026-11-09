<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * What one bucket can spend now: its balance minus the credits active holds reserve. Equal
 * to the balance while holds are off. The minimum balance is not subtracted — a debit may
 * still take the available balance down to `credits.minimum_balance`.
 */
final readonly class GetCreditsAvailableAction
{
    public function __construct(
        private GetCreditsBalanceAction $balance,
        private GetCreditsHeldAction $held,
    ) {}

    /**
     * @throws AmountOverflow when it does not fit a 64-bit integer
     */
    public function execute(Model&Creditable $creditable, ?string $bucket = null, bool $lockForUpdate = false): int
    {
        $bucket ??= CreditsConfig::defaultBucket();

        return LedgerBounds::available(
            $this->balance->execute($creditable, lockForUpdate: $lockForUpdate, bucket: $bucket),
            $this->held->execute($creditable, $bucket, $lockForUpdate),
            $bucket,
        );
    }
}
