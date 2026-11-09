<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\DataTransferObjects;

use Carbon\CarbonInterface;
use RoundlyConsulting\Credits\Support\CreditsConfig;

final readonly class CreditHoldData
{
    /**
     * @param  int  $amount  The credits to reserve; positive.
     * @param  CarbonInterface|null  $expiresAt  When the hold stops reserving; null takes
     *                                           `credits.holds.expire_after_minutes` (or never).
     * @param  array<string, mixed>|null  $meta  Stored to the hold's json column.
     * @param  string|null  $bucket  The named bucket; resolves to the configured default when null.
     */
    public function __construct(
        public int $amount,
        public ?CarbonInterface $expiresAt = null,
        public ?string $description = null,
        public ?array $meta = null,
        public bool $allowOverdraft = false,
        public ?string $bucket = null,
    ) {}

    /**
     * The bucket this hold reserves in, resolving to the configured default.
     */
    public function resolvedBucket(): string
    {
        return $this->bucket ?? CreditsConfig::defaultBucket();
    }
}
