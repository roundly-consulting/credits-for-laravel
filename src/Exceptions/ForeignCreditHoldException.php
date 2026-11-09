<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

use RoundlyConsulting\Credits\Models\CreditHold;

/**
 * A scoped capture or release — `Credits::for($owner)->capture($hold)` — was handed a hold of
 * another owner. The scope is a boundary; nothing was changed.
 */
final class ForeignCreditHoldException extends CreditsException
{
    public function __construct(public readonly CreditHold $hold)
    {
        parent::__construct(
            (string) trans('credits::messages.hold_foreign', ['hold' => (string) $hold->getKey()]),
        );
    }
}
