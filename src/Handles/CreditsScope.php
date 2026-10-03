<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Handles;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundingMode;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Exceptions\BucketNotDenominatedException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Money;

/**
 * One owner's credits in one bucket — `Credits::for($user)`, narrowed with `bucket()` and
 * relaxed with `allowOverdraft()`. Immutable: each of those returns a new scope. Every read
 * and write goes through the manager's flat verbs, so a fake records it.
 */
final readonly class CreditsScope
{
    public function __construct(
        private CreditsManager $manager,
        private Model&Creditable $owner,
        private ?string $bucket = null,
        private bool $allowOverdraft = false,
    ) {}

    /**
     * The same owner, in the named bucket.
     */
    public function bucket(string $bucket): self
    {
        return new self($this->manager, $this->owner, $bucket, $this->allowOverdraft);
    }

    /**
     * A read-only view summing several of this owner's buckets.
     *
     * @param  array<int, string>  $buckets
     */
    public function buckets(array $buckets): CreditBuckets
    {
        return new CreditBuckets($this->manager, $this->owner, $buckets);
    }

    /**
     * The same scope, allowed (or no longer allowed) to take the balance below the minimum.
     */
    public function allowOverdraft(bool $allow = true): self
    {
        return new self($this->manager, $this->owner, $this->bucket, $allow);
    }

    public function balance(?CarbonInterface $at = null): int
    {
        return $this->manager->balance($this->owner, $this->bucket, $at);
    }

    public function has(int $amount = 1, ?CarbonInterface $at = null): bool
    {
        return $this->balance($at) >= $amount;
    }

    /**
     * The balance summed across every bucket this owner has, whichever bucket is scoped.
     */
    public function total(?CarbonInterface $at = null): int
    {
        return $this->manager->total($this->owner, null, $at);
    }

    /**
     * Grant a non-negative amount.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws InvalidArgumentException when the amount is negative — use deduct().
     */
    public function add(int $amount, ?string $description = null, ?array $meta = null): Credit
    {
        return $this->modify($this->unsigned($amount, 'add'), $description, $meta);
    }

    /**
     * Take a non-negative amount off the balance.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws InvalidArgumentException when the amount is negative — use add().
     * @throws InsufficientCreditsException when it would breach the minimum balance.
     */
    public function deduct(int $amount, ?string $description = null, ?array $meta = null): Credit
    {
        return $this->modify(-$this->unsigned($amount, 'deduct'), $description, $meta);
    }

    /**
     * Apply a signed change: positive grants, negative deducts.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws InsufficientCreditsException when a debit would breach the minimum balance.
     */
    public function modify(int $amount, ?string $description = null, ?array $meta = null): Credit
    {
        return $this->manager->modify($this->owner, new CreditChangeData(
            amount: $amount,
            description: $description,
            meta: $meta,
            allowOverdraft: $this->allowOverdraft,
            bucket: $this->bucket,
        ));
    }

    /**
     * Adjust the balance to exactly `$amount` with one delta row; null when it already matches.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws InsufficientCreditsException when lowering it would breach the minimum balance.
     */
    public function setTo(int $amount, ?string $description = null, ?array $meta = null): ?Credit
    {
        return $this->manager->setTo($this->owner, $amount, $description, $meta, $this->allowOverdraft, $this->bucket);
    }

    /**
     * The currency this bucket is denominated in, or null for plain credits.
     */
    public function currency(): ?Currency
    {
        return $this->manager->currency($this->bucket);
    }

    /**
     * A denominated bucket's balance as a Money of its currency.
     *
     * @throws BucketNotDenominatedException
     */
    public function money(?CarbonInterface $at = null): Money
    {
        return Money::ofMinor($this->balance($at), $this->denominated());
    }

    /**
     * Grant a non-negative Money of the bucket's currency.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws BucketNotDenominatedException
     * @throws CurrencyMismatch
     * @throws AmountOverflow
     * @throws InvalidArgumentException when the amount is negative — use deductMoney().
     */
    public function addMoney(Money $amount, ?string $description = null, ?array $meta = null): Credit
    {
        return $this->add($this->minor($amount), $description, $meta);
    }

    /**
     * Take a non-negative Money of the bucket's currency off the balance.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws BucketNotDenominatedException
     * @throws CurrencyMismatch
     * @throws AmountOverflow
     * @throws InvalidArgumentException when the amount is negative — use addMoney().
     * @throws InsufficientCreditsException
     */
    public function deductMoney(Money $amount, ?string $description = null, ?array $meta = null): Credit
    {
        return $this->deduct($this->minor($amount), $description, $meta);
    }

    /**
     * Apply a signed Money of the bucket's currency. Both checks — the currency, and that the
     * amount fits the signed 64-bit ledger — run before anything is written.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws BucketNotDenominatedException
     * @throws CurrencyMismatch
     * @throws AmountOverflow
     * @throws InsufficientCreditsException
     */
    public function modifyMoney(Money $amount, ?string $description = null, ?array $meta = null): Credit
    {
        return $this->modify($this->minor($amount), $description, $meta);
    }

    /**
     * The balance as a locale-free plain decimal string. A denominated bucket is read at its
     * currency exponent (and rendered there unless `$scale` overrides it); every other bucket
     * at `credits.scale`.
     */
    public function format(?int $scale = null, ?RoundingMode $rounding = null, ?CarbonInterface $at = null): string
    {
        return $this->manager->format($this->balance($at), $scale, $rounding, $this->currency()?->exponent);
    }

    /**
     * A denominated bucket's balance, locale-formatted by money's formatter, e.g. "€10.50".
     *
     * @throws BucketNotDenominatedException
     */
    public function formatMoney(?string $locale = null): string
    {
        return $this->money()->format($locale);
    }

    private function denominated(): Currency
    {
        return $this->currency() ?? throw new BucketNotDenominatedException(
            $this->bucket ?? CreditsConfig::defaultBucket(),
        );
    }

    /**
     * The Money as minor units of this bucket.
     *
     * @throws BucketNotDenominatedException
     * @throws CurrencyMismatch
     * @throws AmountOverflow
     */
    private function minor(Money $amount): int
    {
        $currency = $this->denominated();

        if (! $amount->currency()->equals($currency)) {
            throw CurrencyMismatch::between($amount->currency(), $currency);
        }

        return $amount->minorInt();
    }

    private function unsigned(int $amount, string $verb): int
    {
        if ($amount < 0) {
            throw new InvalidArgumentException("{$verb}() takes a non-negative amount, {$amount} given; use modify() for a signed change.");
        }

        return $amount;
    }
}
