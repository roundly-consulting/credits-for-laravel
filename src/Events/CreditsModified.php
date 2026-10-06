<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;

/**
 * A committed ledger change. Dispatched after the transaction that wrote it commits — the
 * change's own, `setTo()`'s, or a host transaction around it — so a rolled-back change is
 * never announced, and a listener that throws can no longer roll the change back.
 */
final class CreditsModified implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Model&Creditable $creditable,
        public readonly Credit $credit,
        public readonly int $balance,
    ) {}
}
