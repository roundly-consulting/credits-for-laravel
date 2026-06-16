<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\DataTransferObjects;

final readonly class CreditChangeData
{
    /**
     * @param  array<string, mixed>|null  $meta  Stored to the ledger row's json column.
     */
    public function __construct(
        public int $amount,
        public ?string $description = null,
        public ?array $meta = null,
        public bool $allowOverdraft = false,
    ) {}

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
        ];
    }
}
