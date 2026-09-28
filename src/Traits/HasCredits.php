<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundingMode;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Exceptions\BucketNotDenominatedException;
use RoundlyConsulting\Credits\Handles\CreditsScope;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Money;

/**
 * Model shorthand for `Credits::for($this)`: every method delegates to CreditsManager, so
 * `Credits::fake()` records changes made through the model too.
 *
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
        return $this->creditsIn($bucket)->balance($at);
    }

    public function hasCredits(int $amount = 1, ?CarbonInterface $at = null, ?string $bucket = null): bool
    {
        return $this->creditsIn($bucket)->has($amount, $at);
    }

    /**
     * Sum the balance across several named buckets. Names are de-duplicated and an empty
     * list yields zero.
     *
     * @param  array<int, string>  $buckets
     */
    public function creditsBalanceForBuckets(array $buckets, ?CarbonInterface $at = null): int
    {
        return $this->creditsIn(null)->buckets($buckets)->balance($at);
    }

    /**
     * Sum the balance across every bucket the entity owns (no bucket filter).
     */
    public function totalCreditsBalance(?CarbonInterface $at = null): int
    {
        return $this->creditsIn(null)->total($at);
    }

    /**
     * Format any integer minor-unit amount into a plain decimal string using the configured
     * scale, with optional per-call scale and rounding-mode overrides — `Credits::format()`.
     */
    public function displayCredits(int $amount, ?int $scale = null, ?RoundingMode $rounding = null): string
    {
        return app(CreditsManager::class)->format($amount, $scale, $rounding);
    }

    /**
     * Format a single bucket's balance into a plain decimal string. A currency-denominated
     * bucket is read at its currency exponent (and rendered at it unless `$scale`
     * overrides); every other bucket at `credits.scale`.
     */
    public function displayCreditsBalance(
        ?string $bucket = null,
        ?int $scale = null,
        ?RoundingMode $rounding = null,
        ?CarbonInterface $at = null,
    ): string {
        return $this->creditsIn($bucket)->format($scale, $rounding, $at);
    }

    /**
     * The currency a bucket is denominated in (`credits.currencies`), or null when its
     * integers are plain credits. Resolved lazily against money's currency registry.
     */
    public function creditsCurrency(?string $bucket = null): ?Currency
    {
        return app(CreditsManager::class)->currency($bucket);
    }

    /**
     * A denominated bucket's balance as a Money of its currency — the same integer
     * `creditsBalance()` returns, read as minor units.
     *
     * @throws BucketNotDenominatedException
     */
    public function creditsBalanceMoney(?string $bucket = null, ?CarbonInterface $at = null): Money
    {
        return $this->creditsIn($bucket)->money($at);
    }

    /**
     * Credit (positive) or debit (negative) a denominated bucket by a Money of its currency.
     * The same overdraft, minimum-balance and locking rules as `modifyCredits()`, the same
     * event — after two checks that happen before anything is written: the currency must be
     * the bucket's, and the amount must fit the signed 64-bit ledger.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws BucketNotDenominatedException
     * @throws CurrencyMismatch
     * @throws AmountOverflow
     */
    public function modifyCreditsMoney(
        Money $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
    ): Credit {
        return $this->creditsIn($bucket)->allowOverdraft($allowOverdraft)->modifyMoney($amount, $description, $meta);
    }

    /**
     * A denominated bucket's balance, locale-formatted by money's formatter
     * (`Money::format()`), e.g. "€10.50".
     *
     * @throws BucketNotDenominatedException
     */
    public function formatCreditsBalance(?string $bucket = null, ?string $locale = null): string
    {
        return $this->creditsIn($bucket)->formatMoney($locale);
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
        return $this->creditsIn($bucket)->allowOverdraft($allowOverdraft)->modify($amount, $description, $meta);
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
        return $this->creditsIn($bucket)->allowOverdraft($allowOverdraft)->setTo($amount, $description, $meta);
    }

    /**
     * @return class-string<Credit>
     */
    protected function creditModel(): string
    {
        return CreditModel::class();
    }

    private function creditsIn(?string $bucket): CreditsScope
    {
        $scope = app(CreditsManager::class)->for($this);

        return $bucket === null ? $scope : $scope->bucket($bucket);
    }
}
