<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Credits\Models\CreditHold;

/**
 * The expiry sweep marked a hold expired. It had already stopped reserving credits when its
 * `expires_at` passed; nothing was written to the ledger. Dispatched after the transaction
 * commits. The owner is `$hold->creditable` — it may have been deleted since.
 */
final class CreditHoldExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly CreditHold $hold,
    ) {}
}
