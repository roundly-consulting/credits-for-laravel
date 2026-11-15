<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundingMode;
use RoundlyConsulting\Credits\Actions\CaptureCreditHoldAction;
use RoundlyConsulting\Credits\Actions\ExpireCreditHoldsAction;
use RoundlyConsulting\Credits\Actions\FormatCreditsAction;
use RoundlyConsulting\Credits\Actions\GetCreditsAvailableAction;
use RoundlyConsulting\Credits\Actions\GetCreditsAvailableWithinAction;
use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\Actions\GetCreditsHeldAction;
use RoundlyConsulting\Credits\Actions\GetCreditsTotalAction;
use RoundlyConsulting\Credits\Actions\HoldCreditsAction;
use RoundlyConsulting\Credits\Actions\ModifyCreditsAction;
use RoundlyConsulting\Credits\Actions\ReleaseCreditHoldAction;
use RoundlyConsulting\Credits\Actions\ResetCreditsAction;
use RoundlyConsulting\Credits\Actions\ResolveBucketCurrencyAction;
use RoundlyConsulting\Credits\Actions\SetCreditsAction;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\DataTransferObjects\CreditHoldData;
use RoundlyConsulting\Credits\DataTransferObjects\CreditResetResult;
use RoundlyConsulting\Credits\DataTransferObjects\CreditsAvailability;
use RoundlyConsulting\Credits\Enums\ResetPeriod;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Exceptions\CreditHoldsDisabledException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Exceptions\InvalidCreditParentException;
use RoundlyConsulting\Credits\Exceptions\LinkedCreditHoldException;
use RoundlyConsulting\Credits\Handles\CreditsScope;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditParents;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;

/**
 * The root of the `Credits` facade, injectable on its own. `for($owner)` is the everyday
 * entry; the flat verbs below are what every scope, the HasCredits trait and the
 * `credits:modify` command end in, each resolving its action from the container — so
 * `Credits::fake()` sees every change and a container override applies.
 *
 * Not final: `Credits::fake()` swaps in CreditsFake, a subtype, so constructor-injected
 * managers keep type-checking under the fake.
 */
class CreditsManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * The owner's credits in the default bucket; narrow with `bucket()` / `buckets()`.
     */
    public function for(Model&Creditable $owner): CreditsScope
    {
        return new CreditsScope($this, $owner);
    }

    /**
     * Format an integer minor-unit amount as a locale-free plain decimal string, read at
     * `$storedScale` (default `credits.scale`) and rendered at `$scale`.
     */
    public function format(int $amount, ?int $scale = null, ?RoundingMode $rounding = null, ?int $storedScale = null): string
    {
        return $this->container->make(FormatCreditsAction::class)->execute($amount, $scale, $rounding, $storedScale);
    }

    /**
     * The currency a bucket is denominated in (`credits.currencies`), or null for plain credits.
     *
     * @throws UnknownCurrency when the mapped code is not registered
     * @throws InvalidMoneyConfiguration when `credits.currencies` is malformed
     */
    public function currency(?string $bucket = null): ?Currency
    {
        return $this->container->make(ResolveBucketCurrencyAction::class)->execute($bucket);
    }

    /**
     * One bucket's balance (the default unless named), optionally as of `$at`.
     */
    public function balance(Model&Creditable $owner, ?string $bucket = null, ?CarbonInterface $at = null): int
    {
        return $this->container->make(GetCreditsBalanceAction::class)->execute($owner, $at, bucket: $bucket);
    }

    /**
     * The balance summed across the given buckets, or across every bucket when null.
     *
     * @param  array<int, string>|null  $buckets
     */
    public function total(Model&Creditable $owner, ?array $buckets = null, ?CarbonInterface $at = null): int
    {
        return $this->container->make(GetCreditsTotalAction::class)->execute($owner, $buckets, $at);
    }

    /**
     * Append one signed ledger row and dispatch CreditsModified.
     *
     * @throws InsufficientCreditsException when a debit would breach the minimum balance.
     */
    public function modify(Model&Creditable $owner, CreditChangeData $data): Credit
    {
        return $this->container->make(ModifyCreditsAction::class)->execute($owner, $data);
    }

    /**
     * Adjust a bucket to an exact balance with one delta row; null when it already matches.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws InsufficientCreditsException when lowering it would breach the minimum balance.
     */
    public function setTo(
        Model&Creditable $owner,
        int $amount,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
        ?string $bucket = null,
    ): ?Credit {
        return $this->container->make(SetCreditsAction::class)
            ->execute($owner, $amount, $description, $meta, $allowOverdraft, $bucket);
    }

    /**
     * The credits one bucket's active holds reserve; 0 while holds are off.
     */
    public function held(Model&Creditable $owner, ?string $bucket = null): int
    {
        return $this->container->make(GetCreditsHeldAction::class)->execute($owner, $bucket);
    }

    /**
     * What one bucket can spend now: its balance minus its held credits.
     */
    public function available(Model&Creditable $owner, ?string $bucket = null): int
    {
        return $this->container->make(GetCreditsAvailableAction::class)->execute($owner, $bucket);
    }

    /**
     * What the owner can spend within its credit parents: the smallest available balance along
     * its chain, and the owner that has it. Its own available balance when it has no parents.
     *
     * @throws InvalidCreditParentException when the owner's parents cannot be walked
     */
    public function availableWithin(Model&Creditable $owner, ?string $bucket = null): CreditsAvailability
    {
        return $this->container->make(GetCreditsAvailableWithinAction::class)->execute($owner, $bucket);
    }

    /**
     * Name every owner's credit parent with one resolver — for owners that cannot implement
     * HasCreditParent themselves. Takes precedence over `creditParent()`; null removes it.
     * Only read while `credits.parents.enabled` is on.
     *
     * @param  (Closure(Model&Creditable): ((Model&Creditable)|null))|null  $resolver
     */
    public function resolveParentsUsing(?Closure $resolver): void
    {
        $this->container->make(CreditParents::class)->resolveUsing($resolver);
    }

    /**
     * Reserve credits: lowers the available balance until the hold is captured, released or expires.
     *
     * @throws CreditHoldsDisabledException when `credits.holds.enabled` is off
     *                                      On an owner with credit parents it must fit every level, and holds each one.
     * @throws InsufficientCreditsException when the hold would breach the minimum balance of any level
     * @throws InvalidArgumentException when the amount is not positive or the expiry has passed
     */
    public function hold(Model&Creditable $owner, CreditHoldData $data): CreditHold
    {
        return $this->container->make(HoldCreditsAction::class)->execute($owner, $data);
    }

    /**
     * Debit a hold — all of it, or `$amount` of it with the rest released, or more than was
     * held when `$allowOverdraft` — and return the ledger row. A hold on an owner with credit
     * parents is captured on every level.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws CreditHoldNotActiveException when it was captured, released or expired already
     * @throws LinkedCreditHoldException when the hold is an ancestor's level of another one
     * @throws InvalidArgumentException when the amount is outside 1..the held amount without an overdraft
     */
    public function capture(CreditHold $hold, ?int $amount = null, ?string $description = null, ?array $meta = null, bool $allowOverdraft = false): Credit
    {
        return $this->container->make(CaptureCreditHoldAction::class)->execute($hold, $amount, $description, $meta, $allowOverdraft);
    }

    /**
     * Free a hold without writing to the ledger — on every level, for an owner with credit parents.
     *
     * @throws CreditHoldNotActiveException when it was captured, released or expired already
     * @throws LinkedCreditHoldException when the hold is an ancestor's level of another one
     */
    public function release(CreditHold $hold): CreditHold
    {
        return $this->container->make(ReleaseCreditHoldAction::class)->execute($hold);
    }

    /**
     * Mark every hold past its expiry as expired; returns how many. Schedule it, or the
     * `credits:expire-holds` command / ExpireCreditHolds job.
     */
    public function expireHolds(?CarbonInterface $now = null, ?string $connection = null): int
    {
        return $this->container->make(ExpireCreditHoldsAction::class)->execute($now, $connection);
    }

    /**
     * Reset a bucket to `$to` once per period; a second call in the same period writes nothing.
     *
     * @param  array<string, mixed>|null  $meta
     *
     * @throws InsufficientCreditsException when lowering it would breach the minimum balance
     * @throws InvalidArgumentException when the period key is blank or too long
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
        return $this->container->make(ResetCreditsAction::class)
            ->execute($owner, $period, $to, $bucket, $description, $meta, $allowOverdraft);
    }
}
