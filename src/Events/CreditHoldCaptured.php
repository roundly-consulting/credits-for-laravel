<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Models\CreditHold;

/**
 * A hold was captured: `$credit` is the ledger debit it wrote (`-$hold->captured_amount`), and
 * `$released` the part of the hold that went back to the available balance (0 for a full
 * capture). CreditsModified fires for the debit as well. Dispatched after the transaction commits.
 */
final class CreditHoldCaptured implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Model&Creditable $creditable,
        public readonly CreditHold $hold,
        public readonly Credit $credit,
        public readonly int $released,
    ) {}
}
