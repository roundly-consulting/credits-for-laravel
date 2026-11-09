<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\CreditHold;

/**
 * Credits were reserved. `$available` is the bucket's available balance this hold left —
 * read under the owner lock, so it is exact. Dispatched after the transaction commits.
 */
final class CreditsHeld implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Model&Creditable $creditable,
        public readonly CreditHold $hold,
        public readonly int $available,
    ) {}
}
