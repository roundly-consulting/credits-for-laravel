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
use RoundlyConsulting\Credits\Actions\ResolveBucketCurrencyAction;
use RoundlyConsulting\Credits\Actions\SetCreditsAction;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Exceptions\BucketNotDenominatedException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Money;

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
        return app(FormatCreditsAction::class)->execute(
            $this->creditsBalance($at, $bucket),
            $scale,
            $rounding,
            $this->creditsCurrency($bucket)?->exponent,
        );
    }

    /**
     * The currency a bucket is denominated in (`credits.currencies`), or null when its
     * integers are plain credits. Resolved lazily against money's currency registry.
     */
    public function creditsCurrency(?string $bucket = null): ?Currency
    {
        return app(ResolveBucketCurrencyAction::class)->execute($bucket);
    }

    /**
     * A denominated bucket's balance as a Money of its currency — the same integer
     * `creditsBalance()` returns, read as minor units.
     *
     * @throws BucketNotDenominatedException
     */
    public function creditsBalanceMoney(?string $bucket = null, ?CarbonInterface $at = null): Money
    {
        $currency = app(ResolveBucketCurrencyAction::class)->denominated($bucket);

        return Money::ofMinor($this->creditsBalance($at, $bucket), $currency);
    }

    /**
     * Credit (positive) or debit (negative) a denominated bucket by a Money of its currency.
     * Runs through `modifyCredits()` — the same overdraft, minimum-balance and locking
     * rules, the same event — after two checks that happen before anything is written:
     * the currency must be the bucket's, and the amount must fit the signed 64-bit ledger.
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
        $currency = app(ResolveBucketCurrencyAction::class)->denominated($bucket);

        if (! $amount->currency()->equals($currency)) {
            throw CurrencyMismatch::between($amount->currency(), $currency);
        }

        return $this->modifyCredits($amount->minorInt(), $description, $meta, $allowOverdraft, $bucket);
    }

    /**
     * A denominated bucket's balance, locale-formatted by money's formatter
     * (`Money::format()`), e.g. "€10.50".
     *
     * @throws BucketNotDenominatedException
     */
    public function formatCreditsBalance(?string $bucket = null, ?string $locale = null): string
    {
        return $this->creditsBalanceMoney($bucket)->format($locale);
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
