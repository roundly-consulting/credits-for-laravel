<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\DataTransferObjects;

use RoundlyConsulting\Credits\Models\Credit;

/**
 * What a periodic reset did for one owner and bucket.
 */
final readonly class CreditResetResult
{
    /**
     * @param  bool  $reset  False when the bucket was already reset in this period: nothing was written.
     * @param  string  $period  The period key the reset was recorded under.
     * @param  Credit|null  $credit  The delta row; null when nothing was written or the balance already matched.
     */
    public function __construct(
        public bool $reset,
        public string $period,
        public ?Credit $credit = null,
    ) {}
}
