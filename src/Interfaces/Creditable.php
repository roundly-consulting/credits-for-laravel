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

    public function creditsBalance(?CarbonInterface $at = null): int;

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function modifyCredits(int $amount, ?string $description = null, ?array $meta = null): void;

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function setCreditsTo(int $amount, ?string $description = null, ?array $meta = null): void;
}
