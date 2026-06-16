<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Credits\Models\Credit;

/**
 * @mixin Model
 */
trait HasCredits
{
    /** @return MorphMany<Credit, $this> */
    public function credits(): MorphMany
    {
        return $this->morphMany($this->creditModel(), 'creditable');
    }

    public function creditsBalance(?CarbonInterface $at = null): int
    {
        $model = $this->creditModel();

        return (new $model)->balance($this, $at);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function modifyCredits(int $amount, ?string $description = null, ?array $meta = null): void
    {
        $this->credits()->create(compact('amount', 'description', 'meta'));
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function setCreditsTo(int $amount, ?string $description = null, ?array $meta = null): void
    {
        $currentBalance = $this->creditsBalance();

        if ($currentBalance === $amount) {
            return;
        }

        $delta = $amount - $currentBalance;

        $this->modifyCredits($delta, $description, $meta);
    }

    /**
     * @return class-string<Credit>
     */
    protected function creditModel(): string
    {
        /** @var class-string<Credit> $model */
        $model = config('credits.model', Credit::class);

        return $model;
    }
}
