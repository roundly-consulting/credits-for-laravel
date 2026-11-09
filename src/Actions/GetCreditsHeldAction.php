<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\Int64;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * The credits one bucket (the default unless named) has reserved by active holds — held and
 * not past their expiry. Zero, without a query, while holds are off. Optionally read under a
 * row lock: the read a debit or a new hold decides on.
 */
final readonly class GetCreditsHeldAction
{
    /**
     * @throws AmountOverflow when the held amounts do not add up to an int64
     */
    public function execute(Model&Creditable $creditable, ?string $bucket = null, bool $lockForUpdate = false): int
    {
        if (! CreditsConfig::holdsEnabled()) {
            return 0;
        }

        $bucket ??= CreditsConfig::defaultBucket();

        // Amounts, not a SUM: `FOR UPDATE` is refused with an aggregate (see
        // GetCreditsBalanceAction), and an owner has few active holds.
        $query = CreditHolds::of($creditable)->bucket($bucket)->active();

        /** @var list<int> $amounts */
        $amounts = ($lockForUpdate ? $query->lockForUpdate() : $query)->pluck('amount')->map(static fn (mixed $amount): int => (int) $amount)->all();

        $exact = Int64::sum(...$amounts);

        return Int64::toInt($exact) ?? throw new AmountOverflow(
            "The credits held in bucket [{$bucket}] add up to [{$exact}], which does not fit a 64-bit integer.",
        );
    }
}
