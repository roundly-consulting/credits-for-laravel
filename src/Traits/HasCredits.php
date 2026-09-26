<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundingMode;
use RoundlyConsulting\Credits\Actions\FormatCreditsAction;
use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\Actions\ModifyCreditsAction;
use RoundlyConsulting\Credits\Actions\SetCreditsAction;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditModel;

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

    public function creditsBalance(?CarbonInterface $at = null, ?string $bucket = null): int
    {
        return app(GetCreditsBalanceAction::class)->execute($this, $at, bucket: $bucket);
    }

    public function hasCredits(int $amount = 1, ?CarbonInterface $at = null, ?string $bucket = null): bool
    {
        return $this->creditsBalance($at, $bucket) >= $amount;
    }

    /**
     * Sum the balance across several named buckets. Names are de-duplicated and an empty
     * list yields zero.
     *
     * @param  array<int, string>  $buckets
     */
    public function creditsBalanceForBuckets(array $buckets, ?CarbonInterface $at = null): int
    {
        return app(GetCreditsBalanceAction::class)->forBuckets($this, $buckets, $at);
    }

    /**
     * Sum the balance across every bucket the entity owns (no bucket filter).
     */
    public function totalCreditsBalance(?CarbonInterface $at = null): int
    {
        return app(GetCreditsBalanceAction::class)->forAllBuckets($this, $at);
    }

    /**
     * Format any integer minor-unit amount into a plain decimal string using the configured
     * scale, with optional per-call scale and rounding-mode overrides.
     */
    public function displayCredits(int $amount, ?int $scale = null, ?RoundingMode $rounding = null): string
    {
        return app(FormatCreditsAction::class)->execute($amount, $scale, $rounding);
    }

    /**
     * Format a single bucket's balance into a plain decimal string.
     */
    public function displayCreditsBalance(
        ?string $bucket = null,
        ?int $scale = null,
        ?RoundingMode $rounding = null,
        ?CarbonInterface $at = null,
    ): string {
        return $this->displayCredits($this->creditsBalance($at, $bucket), $scale, $rounding);
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function modifyCredits(
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
    ): Credit {
        return app(ModifyCreditsAction::class)->execute($this, new CreditChangeData(
            amount: $amount,
            description: $description,
            meta: $meta,
            allowOverdraft: $allowOverdraft,
            bucket: $bucket,
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
        ?string $bucket = null,
    ): ?Credit {
        return app(SetCreditsAction::class)->execute($this, $amount, $description, $meta, $allowOverdraft, $bucket);
    }

    /**
     * @return class-string<Credit>
     */
    protected function creditModel(): string
    {
        return CreditModel::class();
    }
}
