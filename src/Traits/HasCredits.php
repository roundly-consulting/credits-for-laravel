<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\Actions\ModifyCreditsAction;
use RoundlyConsulting\Credits\Actions\SetCreditsAction;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;

/**
 * @mixin Model
 *
 * @phpstan-require-implements Creditable
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
        return app(GetCreditsBalanceAction::class)->execute($this, $at);
    }

    public function hasCredits(int $amount = 1, ?CarbonInterface $at = null): bool
    {
        return $this->creditsBalance($at) >= $amount;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function modifyCredits(
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
    ): Credit {
        return app(ModifyCreditsAction::class)->execute($this, new CreditChangeData(
            amount: $amount,
            description: $description,
            meta: $meta,
            allowOverdraft: $allowOverdraft,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function setCreditsTo(
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
    ): ?Credit {
        return app(SetCreditsAction::class)->execute($this, $amount, $description, $meta, $allowOverdraft);
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
