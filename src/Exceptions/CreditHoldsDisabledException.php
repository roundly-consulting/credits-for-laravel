<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

/**
 * A hold was requested while `credits.holds.enabled` is off. Holds need the
 * `create_credit_holds_table` migration; publish and run it, then switch them on.
 */
final class CreditHoldsDisabledException extends CreditsException
{
    public function __construct()
    {
        parent::__construct((string) trans('credits::messages.holds_disabled'));
    }
}
