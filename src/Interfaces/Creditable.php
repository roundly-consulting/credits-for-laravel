<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Interfaces;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Credits\Models\Credit;

interface Creditable
{
    /** @return MorphMany<Credit, covariant \Illuminate\Database\Eloquent\Model> */
    public function credits(): MorphMany;

    public function creditsBalance(?CarbonInterface $at = null, ?string $bucket = null): int;

    public function hasCredits(int $amount = 1, ?CarbonInterface $at = null, ?string $bucket = null): bool;

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function modifyCredits(
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
    ): Credit;

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function setCreditsTo(
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
    ): ?Credit;
}
