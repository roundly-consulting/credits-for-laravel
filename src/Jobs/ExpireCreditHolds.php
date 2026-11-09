<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RoundlyConsulting\Credits\CreditsManager;

/**
 * The expiry sweep as a queued job — `Schedule::job(new ExpireCreditHolds)->everyMinute()`.
 * Idempotent: each hold is expired once, under its owner's lock.
 */
final class ExpireCreditHolds implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ?string $connectionName = null,
    ) {}

    public function handle(CreditsManager $credits): void
    {
        $credits->expireHolds(connection: $this->connectionName);
    }
}
