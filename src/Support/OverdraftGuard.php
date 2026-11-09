<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The one overdraft rule a debit and a hold share — and CreditsFake with them: unless an
 * overdraft is allowed (per call or by `credits.allow_overdraft`), a debit may not take the
 * spendable balance below `credits.minimum_balance`. Spendable is the balance minus the
 * credits held in the bucket (zero while holds are off).
 *
 * @internal
 */
final class OverdraftGuard
{
    /**
     * Whether a change of `$amount` is guarded at all — so a caller can skip reading the
     * spendable balance when it is not.
     */
    public static function applies(int $amount, bool $allowOverdraft): bool
    {
        return $amount < 0 && ! $allowOverdraft && ! Config::boolean('credits.allow_overdraft');
    }

    /**
     * @param  int  $amount  the signed change; only a negative one is guarded
     *
     * @throws InsufficientCreditsException
     */
    public static function check(Model $creditable, int $spendable, int $amount, bool $allowOverdraft): void
    {
        if (! self::applies($amount, $allowOverdraft)) {
            return;
        }

        $minimum = CreditsConfig::minimumBalance();

        // Exact: a spendable balance near the bottom of int64 minus a large debit is no int —
        // and, the change being negative, below any floor.
        $after = Int64::toInt(Int64::add($spendable, $amount));

        if ($after === null || $after < $minimum) {
            throw new InsufficientCreditsException(
                creditable: $creditable,
                requested: $amount,
                available: $spendable,
                minimum: $minimum,
            );
        }
    }
}
