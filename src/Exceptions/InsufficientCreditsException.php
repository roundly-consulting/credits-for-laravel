<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Support\Int64;

/**
 * A debit would take the bucket below `credits.minimum_balance`. `$available` is the raw
 * balance and `$minimum` the floor the guard enforced, so `$available - $minimum` is what
 * could be spent; the message shows that amount whenever the floor is not 0.
 */
final class InsufficientCreditsException extends CreditsException
{
    public function __construct(
        public readonly Model $creditable,
        public readonly int $requested,
        public readonly int $available,
        public readonly int $minimum = 0,
    ) {
        parent::__construct($minimum === 0
            ? (string) trans('credits::messages.insufficient', [
                'requested' => abs($requested),
                'available' => $available,
            ])
            : (string) trans('credits::messages.insufficient_above_minimum', [
                'requested' => abs($requested),
                'spendable' => Int64::subtract($available, $minimum),
                'minimum' => $minimum,
            ]),
        );
    }
}
