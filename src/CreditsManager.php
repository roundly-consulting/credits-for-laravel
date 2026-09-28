<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundingMode;
use RoundlyConsulting\Credits\Actions\FormatCreditsAction;
use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\Actions\GetCreditsTotalAction;
use RoundlyConsulting\Credits\Actions\ModifyCreditsAction;
use RoundlyConsulting\Credits\Actions\ResolveBucketCurrencyAction;
use RoundlyConsulting\Credits\Actions\SetCreditsAction;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Handles\CreditsScope;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
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
}
