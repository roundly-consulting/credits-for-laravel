<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\DataTransferObjects;

use RoundlyConsulting\Credits\Support\CreditsConfig;

final readonly class CreditChangeData
{
    /**
     * @param  array<string, mixed>|null  $meta  Stored to the ledger row's json column.
     * @param  string|null  $bucket  The named bucket; resolves to the configured default when null.
     */
    public function __construct(
        public int $amount,
        public ?string $description = null,
        public ?array $meta = null,
        public bool $allowOverdraft = false,
        public ?string $bucket = null,
    ) {}

    /**
     * The bucket this change applies to, resolving to the configured default.
     */
    public function resolvedBucket(): string
    {
        return $this->bucket ?? CreditsConfig::defaultBucket();
    }

    /**
     * Column map for persisting the change as a ledger row.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'amount' => $this->amount,
            'description' => $this->description,
            'meta' => $this->meta,
            'bucket' => $this->resolvedBucket(),
        ];
    }
}
