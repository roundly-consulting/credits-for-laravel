<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

use RoundlyConsulting\Credits\Models\CreditHold;

/**
 * A capture or release named an ancestor's level of a hold. A hold on an owner with parents is
 * resolved as a whole, through the hold the call made (its origin): capture or release that one.
 */
final class LinkedCreditHoldException extends CreditsException
{
    public function __construct(
        public readonly CreditHold $hold,
    ) {
        parent::__construct((string) trans('credits::messages.linked_hold', [
            'hold' => (string) $hold->getKey(),
            'origin' => (string) $hold->origin_hold_id,
        ]));
    }
}
