<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Interfaces;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundingMode;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Money;

interface Creditable
{
    /** @return MorphMany<Credit, covariant \Illuminate\Database\Eloquent\Model> */
    public function credits(): MorphMany;

    public function creditsBalance(?CarbonInterface $at = null, ?string $bucket = null): int;

    public function hasCredits(int $amount = 1, ?CarbonInterface $at = null, ?string $bucket = null): bool;

    /**
     * @param  array<int, string>  $buckets
     */
    public function creditsBalanceForBuckets(array $buckets, ?CarbonInterface $at = null): int;

    public function totalCreditsBalance(?CarbonInterface $at = null): int;

    public function displayCredits(int $amount, ?int $scale = null, ?RoundingMode $rounding = null): string;

    /**
     * Render a bucket's balance as a plain decimal string. A currency-denominated bucket
     * renders at its currency exponent unless `$scale` overrides it.
     */
    public function displayCreditsBalance(
        ?string $bucket = null,
        ?int $scale = null,
        ?RoundingMode $rounding = null,
        ?CarbonInterface $at = null,
    ): string;

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

    /**
     * The currency a bucket is denominated in (`credits.currencies`), or null when its
     * integers are plain credits.
     */
    public function creditsCurrency(?string $bucket = null): ?Currency;

    /**
     * A denominated bucket's balance as a Money of its currency.
     */
    public function creditsBalanceMoney(?string $bucket = null, ?CarbonInterface $at = null): Money;

    /**
     * Credit (positive) or debit (negative) a denominated bucket by a Money of its currency.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function modifyCreditsMoney(
        Money $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
    ): Credit;

    /**
     * A denominated bucket's balance, locale-formatted by money's formatter.
     */
    public function formatCreditsBalance(?string $bucket = null, ?string $locale = null): string;
}
