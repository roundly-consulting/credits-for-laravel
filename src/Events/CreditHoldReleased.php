<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Credits\Models\CreditHold;

/**
 * A hold was released in full; nothing was written to the ledger. Dispatched after the
 * transaction commits. The owner is `$hold->creditable`.
 */
final class CreditHoldReleased implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly CreditHold $hold,
    ) {}
}
