<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

use RoundlyConsulting\Credits\Models\CreditHold;

/**
 * A capture or release reached a hold that no longer reserves anything: it was captured,
 * released or expired already — or its `expires_at` passed before the sweep marked it.
 * `$status` is what the hold was found as, `expired` for the latter.
 */
final class CreditHoldNotActiveException extends CreditsException
{
    public function __construct(
        public readonly CreditHold $hold,
        public readonly string $status,
    ) {
        parent::__construct(
            (string) trans('credits::messages.hold_not_active', ['hold' => (string) $hold->getKey(), 'status' => $status]),
        );
    }
}
