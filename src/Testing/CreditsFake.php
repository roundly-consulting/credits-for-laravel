<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Testing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\Int64;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The recording double `Credits::fake()` installs. It writes no ledger row and dispatches no
 * event, and records every change — made through `Credits::for()`, the flat verbs, the
 * HasCredits trait or `credits:modify`, which all go through the manager.
 *
 * Changes land in an in-memory ledger, and balances add it to the owner's real rows, so a
 * grant on the fake can be spent on the fake. The overdraft guard still applies: a debit
 * the real manager would refuse throws InsufficientCreditsException here too, and is not
 * recorded. So does the ledger's int64 bound: a change whose resulting balance, a `setTo()`
 * whose delta, or a total that does not fit a 64-bit integer throws money's AmountOverflow —
 * the same refusal, worded the same, as the real ledger's — and a refused change is not
 * recorded.
 */
final class CreditsFake extends CreditsManager
{
    /** @var list<array{kind: 'added'|'deducted'|'set', owner: Model, amount: int, bucket: string}> */
    private array $recorded = [];

    /** @var list<array{owner: Model, bucket: string, amount: int, at: CarbonImmutable}> */
    private array $ledger = [];

    public function balance(Model&Creditable $owner, ?string $bucket = null, ?CarbonInterface $at = null): int
    {
        $bucket = $this->resolve($bucket);

        $exact = Int64::sum(parent::balance($owner, $bucket, $at), ...$this->pending($owner, $at, static fn (string $row): bool => $row === $bucket));

        // Every change keeps the bucket inside int64; only a point-in-time read of a ledger
        // written out of order (a test that moved the clock back) can sum past it.
        return Int64::toInt($exact) ?? throw new AmountOverflow(
            "The credits balance of bucket [{$bucket}] is [{$exact}], which does not fit a 64-bit integer.",
        );
    }

    /**
     * @param  array<int, string>|null  $buckets
     */
    public function total(Model&Creditable $owner, ?array $buckets = null, ?CarbonInterface $at = null): int
    {
        if ($buckets === []) {
            return 0;
        }

        // De-duplicated like the real total, so a refusal names the same buckets.
        $buckets = $buckets === null ? null : array_values(array_unique($buckets));

        return LedgerBounds::total(Int64::sum(
            parent::total($owner, $buckets, $at),
            ...$this->pending($owner, $at, static fn (string $row): bool => $buckets === null || in_array($row, $buckets, true)),
        ), $buckets);
    }

    /**
     * Records a grant or a deduction by its sign and returns an unsaved ledger row.
     */
    public function modify(Model&Creditable $owner, CreditChangeData $data): Credit
    {
        $credit = $this->apply($owner, $data);

        $this->record($data->amount < 0 ? 'deducted' : 'added', $owner, abs($data->amount), $data->resolvedBucket());

        return $credit;
    }

    /**
     * Records the requested target — even when it already matches — and applies the delta.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function setTo(
        Model&Creditable $owner,
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
    ): ?Credit {
        $data = new CreditChangeData(
            amount: LedgerBounds::delta($amount, $this->balance($owner, $bucket), $this->resolve($bucket)),
            description: $description,
            meta: $meta,
            allowOverdraft: $allowOverdraft,
            bucket: $bucket,
        );

        $credit = $data->amount === 0 ? null : $this->apply($owner, $data);

        $this->record('set', $owner, $amount, $data->resolvedBucket());

        return $credit;
    }

    /**
     * Assert credits were added to the owner — any grant, or one of `$amount` and/or in `$bucket`.
     */
    public function assertAdded(Model $owner, ?int $amount = null, ?string $bucket = null): void
    {
        Assert::assertTrue(
            $this->matches('added', $owner, $amount, $bucket),
            'Expected credits to be added'.$this->describe($owner, $amount, $bucket).', but none were.',
        );
    }

    public function assertNothingAdded(): void
    {
        Assert::assertFalse($this->recordedAny('added'), 'Expected no credits to be added, but some were.');
    }

    /**
     * Assert credits were deducted from the owner — any deduction, or one of `$amount`
     * (positive, as passed to `deduct()`) and/or in `$bucket`.
     */
    public function assertDeducted(Model $owner, ?int $amount = null, ?string $bucket = null): void
    {
        Assert::assertTrue(
            $this->matches('deducted', $owner, $amount, $bucket),
            'Expected credits to be deducted'.$this->describe($owner, $amount, $bucket).', but none were.',
        );
    }

    public function assertNothingDeducted(): void
    {
        Assert::assertFalse($this->recordedAny('deducted'), 'Expected no credits to be deducted, but some were.');
    }

    /**
     * Assert the owner's balance was set — to any amount, or to `$amount` and/or in `$bucket`.
     */
    public function assertSet(Model $owner, ?int $amount = null, ?string $bucket = null): void
    {
        Assert::assertTrue(
            $this->matches('set', $owner, $amount, $bucket),
            'Expected a balance to be set'.$this->describe($owner, $amount, $bucket).', but none was.',
        );
    }

    public function assertNothingSet(): void
    {
        Assert::assertFalse($this->recordedAny('set'), 'Expected no balance to be set, but one was.');
    }

    /**
     * Assert no credits were added, deducted or set at all.
     */
    public function assertNothingModified(): void
    {
        Assert::assertSame([], $this->recorded, 'Expected no credits to be modified, but some were.');
    }

    /**
     * The overdraft guard and the int64 bound, against the fake's balance and in the real
     * ledger's order, then an in-memory ledger entry.
     *
     * @throws InsufficientCreditsException
     * @throws AmountOverflow
     */
    private function apply(Model&Creditable $owner, CreditChangeData $data): Credit
    {
        $bucket = $data->resolvedBucket();
        $available = $this->balance($owner, $bucket);

        if ($data->amount < 0 && ! $data->allowOverdraft && ! Config::boolean('credits.allow_overdraft')) {
            $minimum = CreditsConfig::minimumBalance();

            if ($available + $data->amount < $minimum) {
                throw new InsufficientCreditsException(creditable: $owner, requested: $data->amount, available: $available, minimum: $minimum);
            }
        }

        LedgerBounds::balanceAfter($available, $data->amount, $bucket);

        // Stamped to the second, like the real `created_at`.
        $this->ledger[] = ['owner' => $owner, 'bucket' => $bucket, 'amount' => $data->amount, 'at' => CarbonImmutable::now()->startOfSecond()];

        /** @var Credit $credit */
        $credit = $owner->credits()->make($data->toAttributes());

        return $credit;
    }

    /**
     * @param  'added'|'deducted'|'set'  $kind
     */
    private function record(string $kind, Model $owner, int $amount, string $bucket): void
    {
        $this->recorded[] = ['kind' => $kind, 'owner' => $owner, 'amount' => $amount, 'bucket' => $bucket];
    }

    /**
     * The owner's in-memory amounts, unsummed: several can add up past int64, so the caller
     * adds them exactly.
     *
     * @param  callable(string): bool  $inBucket
     * @return list<int>
     */
    private function pending(Model $owner, ?CarbonInterface $at, callable $inBucket): array
    {
        $amounts = [];
        // The real ledger stores `created_at` and binds `$at` to the second.
        $upTo = $at === null ? null : CarbonImmutable::instance($at)->startOfSecond();

        foreach ($this->ledger as $row) {
            if ($row['owner']->is($owner) && $inBucket($row['bucket']) && ($upTo === null || $row['at']->lessThanOrEqualTo($upTo))) {
                $amounts[] = $row['amount'];
            }
        }

        return $amounts;
    }

    private function matches(string $kind, Model $owner, ?int $amount, ?string $bucket): bool
    {
        foreach ($this->recorded as $entry) {
            if ($entry['kind'] === $kind
                && $entry['owner']->is($owner)
                && ($amount === null || $entry['amount'] === $amount)
                && ($bucket === null || $entry['bucket'] === $bucket)) {
                return true;
            }
        }

        return false;
    }

    private function recordedAny(string $kind): bool
    {
        foreach ($this->recorded as $entry) {
            if ($entry['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }

    private function describe(Model $owner, ?int $amount, ?string $bucket): string
    {
        return ' for '.$owner::class.' #'.var_export($owner->getKey(), true)
            .($amount === null ? '' : ", amount {$amount}")
            .($bucket === null ? '' : ", bucket \"{$bucket}\"");
    }

    private function resolve(?string $bucket): string
    {
        return $bucket ?? CreditsConfig::defaultBucket();
    }
}
