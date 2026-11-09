<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Testing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\DataTransferObjects\CreditHoldData;
use RoundlyConsulting\Credits\DataTransferObjects\CreditResetResult;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Enums\ResetPeriod;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\CreditResets;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\Int64;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Credits\Support\LedgerConnection;
use RoundlyConsulting\Credits\Support\OverdraftGuard;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * The recording double `Credits::fake()` installs. It writes no ledger row and dispatches no
 * event, and records every change — made through `Credits::for()`, the flat verbs, the
 * HasCredits trait or `credits:modify`, which all go through the manager.
 *
 * Changes land in an in-memory ledger, and balances add it to the owner's real rows, so a
 * grant on the fake can be spent on the fake. The overdraft guard still applies: a debit
 * the real manager would refuse throws InsufficientCreditsException here too, and is not
 * recorded. So does the ledger's int64 bound: a change whose resulting balance, a `setTo()`
 * whose delta, or a balance or total that does not fit a 64-bit integer throws money's
 * AmountOverflow — the same refusal, worded the same, as the real ledger's — and a refused
 * change is not recorded.
 *
 * Holds work the same way: a hold is kept in memory (an unsaved CreditHold) and lowers the
 * fake's available balance; capturing one debits the in-memory ledger, releasing or expiring
 * one frees it. A hold that was saved before the fake was installed can be captured or
 * released too — its row is left untouched. A periodic reset is idempotent per period, in
 * memory and against the reset markers already in the database.
 */
final class CreditsFake extends CreditsManager
{
    /**
     * `amount` is signed as applied — a deduction is negative — the target for a set or a
     * reset, and the held / captured / released amount for a hold.
     *
     * @var list<array{kind: 'added'|'deducted'|'set'|'held'|'captured'|'released'|'reset', owner: Model, amount: int, bucket: string, period: string|null}>
     */
    private array $recorded = [];

    /**
     * Holds the fake made, and saved holds it resolved; `real` marks the latter, whose row
     * still counts as held in the database.
     *
     * @var list<array{hold: CreditHold, owner: Model&Creditable, real: bool}>
     */
    private array $holds = [];

    /** @var list<string> */
    private array $resets = [];

    /** @var list<array{owner: Model, bucket: string, amount: int, at: CarbonImmutable}> */
    private array $ledger = [];

    public function balance(Model&Creditable $owner, ?string $bucket = null, ?CarbonInterface $at = null): int
    {
        $bucket = $this->resolve($bucket);

        // Every change keeps the bucket inside int64; only a point-in-time read of a ledger
        // written out of order (a test that moved the clock back) can sum past it.
        return LedgerBounds::balance(
            Int64::sum(parent::balance($owner, $bucket, $at), ...$this->pending($owner, $at, static fn (string $row): bool => $row === $bucket)),
            $bucket,
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

        // Signed: the magnitude of PHP_INT_MIN is not an int.
        $this->record($data->amount < 0 ? 'deducted' : 'added', $owner, $data->amount, $data->resolvedBucket());

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

    public function held(Model&Creditable $owner, ?string $bucket = null): int
    {
        $bucket = $this->resolve($bucket);
        $now = CarbonImmutable::now();
        $amounts = [parent::held($owner, $bucket)];

        foreach ($this->holds as $entry) {
            $hold = $entry['hold'];

            if (! $entry['owner']->is($owner) || $hold->bucket !== $bucket) {
                continue;
            }

            if (! $entry['real'] && $hold->isActive($now)) {
                $amounts[] = $hold->amount;
            }

            // A saved hold the fake resolved still reads as held from its row.
            if ($entry['real'] && ! $hold->isActive($now) && ($hold->expires_at === null || $hold->expires_at->greaterThan($now))) {
                $amounts[] = -$hold->amount;
            }
        }

        return LedgerBounds::total(Int64::sum(...$amounts), [$bucket]);
    }

    public function available(Model&Creditable $owner, ?string $bucket = null): int
    {
        $bucket = $this->resolve($bucket);

        return LedgerBounds::available($this->balance($owner, $bucket), $this->held($owner, $bucket), $bucket);
    }

    /**
     * Records the hold and returns it unsaved; the overdraft guard applies to the fake's
     * available balance, as the real one's does.
     */
    public function hold(Model&Creditable $owner, CreditHoldData $data): CreditHold
    {
        CreditHolds::ensureEnabled();

        $now = CarbonImmutable::now();
        $expiresAt = CreditHolds::expiry($data, $now);
        $bucket = $data->resolvedBucket();
        $available = $this->available($owner, $bucket);

        OverdraftGuard::check($owner, $available, -$data->amount, $data->allowOverdraft);
        LedgerBounds::available($available, $data->amount, $bucket);

        $hold = new CreditHold([
            'creditable_type' => $owner->getMorphClass(),
            'creditable_id' => $owner->getKey(),
            'bucket' => $bucket,
            'amount' => $data->amount,
            'status' => CreditHoldStatus::Held,
            'description' => $data->description,
            'meta' => $data->meta,
            'expires_at' => $expiresAt,
        ]);

        $this->holds[] = ['hold' => $hold, 'owner' => $owner, 'real' => false];
        $this->record('held', $owner, $data->amount, $bucket);

        return $hold;
    }

    /**
     * Records the capture and debits the in-memory ledger; the hold is marked captured in memory.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function capture(CreditHold $hold, ?int $amount = null, ?string $description = null, ?array $meta = null): Credit
    {
        $entry = $this->holdEntry($hold);
        $tracked = $entry['hold'];
        $captured = CreditHolds::captureAmount($tracked, $amount);
        $now = CarbonImmutable::now();

        CreditHolds::ensureActive($tracked, $now);

        $credit = $this->apply($entry['owner'], new CreditChangeData(
            amount: -$captured,
            description: $description ?? $tracked->description,
            meta: $meta ?? $tracked->meta,
            allowOverdraft: true,
            bucket: $tracked->bucket,
        ));

        $this->resolveHold($hold, $tracked, ['status' => CreditHoldStatus::Captured, 'captured_amount' => $captured, 'resolved_at' => $now]);
        $this->record('captured', $entry['owner'], $captured, $tracked->bucket);

        return $credit;
    }

    /**
     * Records the release; the hold is marked released in memory.
     */
    public function release(CreditHold $hold): CreditHold
    {
        $entry = $this->holdEntry($hold);
        $tracked = $entry['hold'];
        $now = CarbonImmutable::now();

        CreditHolds::ensureActive($tracked, $now);

        $this->resolveHold($hold, $tracked, ['status' => CreditHoldStatus::Released, 'resolved_at' => $now]);
        $this->record('released', $entry['owner'], $tracked->amount, $tracked->bucket);

        return $hold;
    }

    /**
     * Expires the fake's own stale holds in memory and returns how many; saved holds are left
     * to the real sweep.
     */
    public function expireHolds(?CarbonInterface $now = null, ?string $connection = null): int
    {
        if (! CreditsConfig::holdsEnabled()) {
            return 0;
        }

        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $expired = 0;

        foreach ($this->holds as $entry) {
            $hold = $entry['hold'];

            if (! $entry['real'] && $hold->status === CreditHoldStatus::Held && $hold->expires_at !== null && ! $hold->expires_at->greaterThan($now)) {
                $hold->forceFill(['status' => CreditHoldStatus::Expired, 'resolved_at' => $now]);
                $expired++;
            }
        }

        return $expired;
    }

    /**
     * Records the reset once per owner, bucket and period, and applies the delta.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function reset(
        Model&Creditable $owner,
        ResetPeriod|string $period,
        int $to = 0,
        ?string $bucket = null,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
    ): CreditResetResult {
        $key = CreditResets::key($period, CarbonImmutable::now());
        $bucket = $this->resolve($bucket);
        $marker = $owner->getMorphClass()."\0".$owner->getKey()."\0{$bucket}\0{$key}";

        if (in_array($marker, $this->resets, true) || CreditResets::claimed(LedgerConnection::of($owner), $owner, $bucket, $key)) {
            return new CreditResetResult(reset: false, period: $key);
        }

        $delta = LedgerBounds::delta($to, $this->balance($owner, $bucket), $bucket);
        $credit = $delta === 0 ? null : $this->apply($owner, new CreditChangeData(
            amount: $delta,
            description: $description,
            meta: $meta ?? ['reset_period' => $key],
            allowOverdraft: $allowOverdraft,
            bucket: $bucket,
        ));

        $this->resets[] = $marker;
        $this->record('reset', $owner, $to, $bucket, $key);

        return new CreditResetResult(reset: true, period: $key, credit: $credit);
    }

    /**
     * Assert credits were held for the owner — any hold, or one of `$amount` and/or in `$bucket`.
     */
    public function assertHeld(Model $owner, ?int $amount = null, ?string $bucket = null): void
    {
        Assert::assertTrue(
            $this->matches('held', $owner, $amount, $bucket),
            'Expected credits to be held'.$this->describe($owner, $amount, $bucket).', but none were.',
        );
    }

    public function assertNothingHeld(): void
    {
        Assert::assertFalse($this->recordedAny('held'), 'Expected no credits to be held, but some were.');
    }

    /**
     * Assert a hold of the owner was captured — any, or `$amount` of it and/or in `$bucket`.
     */
    public function assertCaptured(Model $owner, ?int $amount = null, ?string $bucket = null): void
    {
        Assert::assertTrue(
            $this->matches('captured', $owner, $amount, $bucket),
            'Expected a hold to be captured'.$this->describe($owner, $amount, $bucket).', but none was.',
        );
    }

    public function assertNothingCaptured(): void
    {
        Assert::assertFalse($this->recordedAny('captured'), 'Expected no hold to be captured, but one was.');
    }

    /**
     * Assert a hold of the owner was released — any, or one of `$amount` and/or in `$bucket`.
     */
    public function assertReleased(Model $owner, ?int $amount = null, ?string $bucket = null): void
    {
        Assert::assertTrue(
            $this->matches('released', $owner, $amount, $bucket),
            'Expected a hold to be released'.$this->describe($owner, $amount, $bucket).', but none was.',
        );
    }

    public function assertNothingReleased(): void
    {
        Assert::assertFalse($this->recordedAny('released'), 'Expected no hold to be released, but one was.');
    }

    /**
     * Assert the owner's bucket was reset — any reset, or one in `$bucket` and/or for the
     * period key `$period`.
     */
    public function assertReset(Model $owner, ?string $bucket = null, ?string $period = null): void
    {
        foreach ($this->recorded as $entry) {
            if ($entry['kind'] === 'reset' && $entry['owner']->is($owner)
                && ($bucket === null || $entry['bucket'] === $bucket)
                && ($period === null || $entry['period'] === $period)) {
                return;
            }
        }

        Assert::fail('Expected a reset'.$this->describe($owner, null, $bucket).($period === null ? '' : ", period \"{$period}\"").', but none was.');
    }

    public function assertNothingReset(): void
    {
        Assert::assertFalse($this->recordedAny('reset'), 'Expected no reset, but one was.');
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
        $balance = $this->balance($owner, $bucket);

        if (OverdraftGuard::applies($data->amount, $data->allowOverdraft)) {
            OverdraftGuard::check($owner, LedgerBounds::available($balance, $this->held($owner, $bucket), $bucket), $data->amount, $data->allowOverdraft);
        }

        LedgerBounds::balanceAfter($balance, $data->amount, $bucket);

        // Stamped to the second, like the real `created_at`.
        $this->ledger[] = ['owner' => $owner, 'bucket' => $bucket, 'amount' => $data->amount, 'at' => CarbonImmutable::now()->startOfSecond()];

        /** @var Credit $credit */
        $credit = $owner->credits()->make($data->toAttributes());

        return $credit;
    }

    /**
     * @param  'added'|'deducted'|'set'|'held'|'captured'|'released'|'reset'  $kind
     */
    private function record(string $kind, Model $owner, int $amount, string $bucket, ?string $period = null): void
    {
        $this->recorded[] = ['kind' => $kind, 'owner' => $owner, 'amount' => $amount, 'bucket' => $bucket, 'period' => $period];
    }

    /**
     * The fake's record of a hold: one it made (the same instance), or a saved one it meets
     * for the first time — tracked from then on, its row untouched.
     *
     * @return array{hold: CreditHold, owner: Model&Creditable, real: bool}
     */
    private function holdEntry(CreditHold $hold): array
    {
        foreach ($this->holds as $entry) {
            if ($entry['hold'] === $hold || ($hold->exists && $entry['real'] && $entry['hold']->is($hold))) {
                return $entry;
            }
        }

        $entry = ['hold' => clone $hold, 'owner' => CreditHolds::owner($hold), 'real' => true];
        $this->holds[] = $entry;

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveHold(CreditHold $hold, CreditHold $tracked, array $attributes): void
    {
        $tracked->forceFill($attributes);

        if ($hold !== $tracked) {
            $hold->forceFill($attributes);
        }
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
                // A deduction's `$amount` is positive, as passed to `deduct()`; compared signed.
                && ($amount === null || $entry['amount'] === ($kind === 'deducted' ? -$amount : $amount))
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
