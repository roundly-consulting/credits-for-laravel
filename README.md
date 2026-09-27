<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/credits-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=credits-for-laravel">
    <img src="art/hero.png" alt="Credits for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

# Credits for Laravel

Manage credits / wallet balance on any Eloquent entity. Each credit change is stored as an
immutable, time-stamped ledger row (positive to grant, negative to deduct), and an entity's
balance is the sum of its rows — optionally as of a point in time.

## Requirements

- PHP 8.4 with `ext-bcmath`
- Laravel 12 or 13
- [`roundly-consulting/package-toolkit-for-laravel`](https://github.com/roundly-consulting/package-toolkit-for-laravel)
  (the shared package bootstrapper — a hard dependency, installs automatically)
- [`roundly-consulting/money-for-laravel`](https://github.com/roundly-consulting/money-for-laravel)
  (the display rounding core and currency-denominated buckets — a hard dependency, installs
  automatically)

## Integrates with

- **[money-for-laravel](https://github.com/roundly-consulting/money-for-laravel)** — always on.
  The display helpers rescale and round on money's exact integer-string core
  (`MinorUnits`) with PHP 8.4's native `\RoundingMode`, and any bucket can be denominated in a
  money currency — ISO (`EUR`) or custom (`PTS` loyalty points) — to read and write it as a
  `Money`. See [Currency-denominated buckets](#currency-denominated-buckets).

## Installation

Install the package via Composer:

```bash
composer require roundly-consulting/credits-for-laravel
```

Publish and run the migration. The package does **not** auto-load it — publishing copies the
`credits` migration into your `database/migrations`, where you own it, so a bare
`php artisan migrate` before publishing creates nothing:

```bash
php artisan vendor:publish --tag="credits-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="credits-config"
```

Optionally publish the translations:

```bash
php artisan vendor:publish --tag="credits-translations"
```

## Configuration

The published config file lives at `config/credits.php`:

```php
<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Models\Credit;

return [

    'model' => Credit::class,

    'primary_key_type' => env('CREDITS_PRIMARY_KEY_TYPE', 'bigint'),

    'allow_overdraft' => env('CREDITS_ALLOW_OVERDRAFT', false),

    'minimum_balance' => 0,

    'default_bucket' => 'default',

    'scale' => 0,

    'rounding' => env('CREDITS_ROUNDING', 'half_away_from_zero'),

    'currencies' => [
        // 'store_credit' => 'EUR',
        // 'points' => 'PTS',
    ],

    'modifiable' => [
        //
    ],

];
```

| Key | Type | Default | Env | Purpose |
|---|---|---|---|---|
| `model` | `class-string<RoundlyConsulting\Credits\Models\Credit>` | `Credit::class` | — | The Eloquent model used to store credit rows. Override with your own subclass to customise behaviour or the table. |
| `primary_key_type` | `string` | `'bigint'` | `CREDITS_PRIMARY_KEY_TYPE` | The primary-key strategy of the `credits` table: `bigint`, `uuid` or `ulid`. Fixed when the migration first runs. See [Key types](#key-types). |
| `allow_overdraft` | `bool` | `false` | `CREDITS_ALLOW_OVERDRAFT` | When `false`, a deduction that would take the balance below `minimum_balance` is rejected with an `InsufficientCreditsException`. Set `true` to permit negative balances globally. |
| `minimum_balance` | `int` | `0` | — | The floor enforced when overdraft is disallowed. |
| `default_bucket` | `string` | `'default'` | — | The bucket used for reads and writes when a call omits one. A bucket-less balance query returns this bucket's balance only — it does not sum across buckets. See [Named buckets](#named-buckets). |
| `scale` | `int` | `0` | — | The number of decimal places the stored integer encodes (the integer-minor-unit convention for fractional credits). Used by the display helpers; a [currency-denominated bucket](#currency-denominated-buckets) ignores it (its scale is the currency exponent). See [Displaying balances](#displaying-balances). |
| `rounding` | `string` | `'half_away_from_zero'` | `CREDITS_ROUNDING` | Default rounding mode for the display helpers when a requested display scale is smaller than the stored scale: a PHP 8.4 `\RoundingMode` case in snake_case — `half_away_from_zero`, `half_towards_zero`, `half_even` (banker's), `half_odd`, `towards_zero`, `away_from_zero`, `positive_infinity`, `negative_infinity`. An unknown value (including the legacy `PHP_ROUND_*` integers) throws `InvalidMoneyConfiguration` on first use. Overridable per call with a `\RoundingMode`. See [Upgrading](#upgrading). |
| `currencies` | `array<string, string>` | `[]` | — | Bucket name → money currency code (ISO or custom). A listed bucket's integers are minor units of that currency. Resolved lazily on first use. See [Currency-denominated buckets](#currency-denominated-buckets). |
| `modifiable` | `array<Closure>` | `[]` | — | Resolvers invoked by the `credits:modify` command. Each closure receives a `$modify` callback that applies the requested change to a `Creditable` entity. |

The package ships with sensible defaults and works with **zero** host configuration.

### Key types

`primary_key_type` sets the type of the `credits` table's own `id` column, and the model
follows it — `bigint` (auto-incrementing, the default), `uuid` or `ulid`. It is read when
the migration runs, so choose it **before** you publish and migrate; changing it afterwards
is a data migration, not a config change.

The default is `bigint` for a reason worth understanding before you change it. A `Credit` is
a thing other packages point *at* polymorphically, and a Laravel morph column
(`$table->morphs('subject')`) is an unsigned bigint. On a strict engine such as PostgreSQL,
a `uuid` credit id will not go into one:

```
SQLSTATE[22P02]: invalid input syntax for type bigint: "019f6f33-22b8-737f-a581-849e7cdc517a"
```

SQLite will **not** warn you about this — its type affinity stores the string in an integer
column silently, so a green SQLite suite proves nothing here.

> **Constraint:** this assumes every morph target in your application shares one key type.
> If you set `CREDITS_PRIMARY_KEY_TYPE=uuid`, the models on the other end of your
> polymorphic relations need to be uuid-keyed too, and the packages owning those columns
> need to agree. A mixed application — a `uuid` `Credit` and a `bigint` `Comment` both
> pointed at by the same morph column — is not supported by this package, by Laravel's own
> `morphs()`/`uuidMorphs()` split, or by anything else. Pick one key type per application.

## Usage

### Make a model creditable

Add the `HasCredits` trait to any Eloquent model. Implementing the `Creditable` interface is
optional but recommended so the contract is explicit (the `credits:modify` command depends on
it).

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Traits\HasCredits;

final class User extends Model implements Creditable
{
    use HasCredits;
}
```

### Grant and deduct credits

`modifyCredits()` appends a ledger row. Use a positive amount to grant, a negative amount to
deduct. The optional `$description` and `$meta` are stored on the row.

```php
$user->modifyCredits(100, 'signup bonus');
$user->modifyCredits(-30, 'purchase', ['order_id' => 42]);
```

`modifyCredits()` returns the recorded `Credit` row.

### Overdraft protection

By default a deduction that would drive the balance below `minimum_balance` (zero by default)
is **rejected** with an `InsufficientCreditsException` — balances cannot silently go negative.

```php
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;

try {
    $user->modifyCredits(-1000, 'big purchase');
} catch (InsufficientCreditsException $e) {
    // $e->requested and $e->available carry the numbers; $e->creditable is the entity.
}
```

Allow a negative balance for a single call with `allowOverdraft`, or globally via the
`allow_overdraft` config / `CREDITS_ALLOW_OVERDRAFT` env var:

```php
$user->modifyCredits(-1000, 'manual debit', allowOverdraft: true);
```

The read-then-write is wrapped in a database transaction with a row-level lock
(`lockForUpdate`), so concurrent deductions cannot both breach the floor.

### Read the balance

```php
$user->creditsBalance();            // 70

// Balance as of a point in time:
$user->creditsBalance(now()->subWeek());
```

### Check available credits

```php
$user->hasCredits();        // true if balance >= 1
$user->hasCredits(100);     // true if balance >= 100
$user->hasCredits(100, now()->subWeek());
```

### Set an exact balance

`setCreditsTo()` computes the delta from the current balance and records a single adjusting
row (it does nothing if the balance already matches).

```php
$user->setCreditsTo(500, 'manual adjustment');
```

### Access the ledger

`credits()` is a standard `MorphMany` relationship, so you can query the underlying rows:

```php
$user->credits()->latest()->get();
```

### Named buckets

Credits can be split into independent, named pools on the same entity — for example
`promotional` and `purchased` credit that should never be spent against each other. Every
write and read method accepts an optional `bucket` argument.

```php
$user->modifyCredits(100, 'welcome promo', bucket: 'promotional');
$user->modifyCredits(50, 'top-up', bucket: 'purchased');

$user->creditsBalance(bucket: 'promotional'); // 100
$user->creditsBalance(bucket: 'purchased');   // 50
```

Balances are fully isolated per bucket, and the overdraft guard is enforced **per bucket** —
a deduction against the `purchased` bucket cannot draw on `promotional` credit.

When you omit the bucket, the configured `default_bucket` (`'default'`) is used for **both**
reads and writes. A bucket-less balance query therefore returns the default bucket's balance
only; it does **not** sum across every bucket:

```php
$user->modifyCredits(25);          // lands in the 'default' bucket
$user->creditsBalance();           // 25  (the default bucket only)
$user->creditsBalance(bucket: 'promotional'); // 100
```

Existing single-pool usage is unchanged: every call without a bucket continues to operate on
one pool (the `default` bucket). `setCreditsTo()` and `hasCredits()` accept the same `bucket`
argument, and the `credits:modify` command exposes a `--bucket=` option.

#### Totals across buckets

When you do want a combined figure, sum across several named buckets or across every bucket
the entity owns:

```php
// Sum a chosen set of buckets (names are de-duplicated; an empty list returns 0).
$user->creditsBalanceForBuckets(['promotional', 'purchased']); // 150

// Sum every bucket on the entity.
$user->totalCreditsBalance(); // 175

// Both accept an optional point-in-time argument.
$user->totalCreditsBalance(now()->subWeek());
```

### Fractional credits (scale)

Credits are stored as whole integers to avoid floating-point drift. To represent fractional
credits, treat the stored value as **minor units** and set `scale` in the config to the number
of decimal places they represent. For example, with `scale = 2` a stored value of `150`
represents `1.50` credits — store and deduct the minor units, and use the display helpers
below to render them. Storage stays an integer (`bigInteger`) and the core math is unchanged.

### Displaying balances

The display helpers turn an integer minor-unit amount into a **locale-free plain decimal
string** using the configured `scale`. They apply no thousands separators or locale
formatting — wrap the result with [`Illuminate\Support\Number`](https://laravel.com/docs/helpers#numbers)
yourself if you need that.

```php
config(['credits.scale' => 2]);

$user->modifyCredits(123450); // stores 123450 minor units

$user->displayCreditsBalance();      // "1234.50"  (a single bucket's balance)
$user->displayCredits(123450);       // "1234.50"  (format any integer amount)
```

`displayCredits()` and `displayCreditsBalance()` take an optional per-call `scale` override
(the number of decimal places to render) and an optional `rounding` mode. When the requested
display scale is **smaller** than the stored scale, the dropped digits are rounded once using
the mode — a native `\RoundingMode`, defaulting to `config('credits.rounding')`
(`half_away_from_zero`):

```php
$user->displayCredits(123450, scale: 0);                                         // "1235"
$user->displayCredits(123450, scale: 0, rounding: \RoundingMode::HalfTowardsZero); // "1234"
$user->displayCredits(-123450);                                                  // "-1234.50"
```

A display scale **larger** than the stored scale renders exactly — the rescale runs on
arbitrary-precision integer strings, so even `PHP_INT_MAX` never degrades to a float:

```php
config(['credits.scale' => 0]);

$user->displayCredits(PHP_INT_MAX, scale: 6); // "9223372036854775807.000000"
```

Display scales are capped at 36 decimal places (`MinorUnits::MAX_SCALE`).

Multi-bucket display composes from the totals above — there are no dedicated display-total
methods:

```php
$user->displayCredits($user->totalCreditsBalance());                       // formatted grand total
$user->displayCredits($user->creditsBalanceForBuckets(['promotional', 'purchased']));
```

The reusable formatting core is `RoundlyConsulting\Credits\Actions\FormatCreditsAction`
(`execute(int $amount, ?int $scale = null, ?\RoundingMode $rounding = null, ?int $storedScale = null): string`),
which the trait methods delegate to. It is a thin layer over money-for-laravel's
`MinorUnits::rescale()` / `MinorUnits::toDecimal()`; `$storedScale` defaults to
`credits.scale` (a denominated bucket passes its currency exponent).

### Currency-denominated buckets

A bucket can be **denominated** in a [money-for-laravel](https://github.com/roundly-consulting/money-for-laravel)
currency. Its integer amounts are then minor units of that currency — cents for `EUR`, whole
points for a custom `PTS` — and it gains money-typed helpers that refuse any other currency:

```php
// config/credits.php
'currencies' => [
    'store_credit' => 'EUR',
    'points' => 'PTS',   // a custom currency, see below
],
```

```php
use RoundlyConsulting\Money\Money;

$user->creditsCurrency('store_credit');   // Currency EUR   (null for a plain bucket)

$user->modifyCreditsMoney(Money::ofMajor('25.00', 'EUR'), 'gift card', bucket: 'store_credit');
$user->modifyCreditsMoney(Money::ofMinor(-1050, 'EUR'), 'order #42', bucket: 'store_credit');

$user->creditsBalanceMoney('store_credit');          // Money EUR 14.50
$user->creditsBalanceMoney('store_credit')->minor(); // "1450" — money amounts are strings
$user->creditsBalance(bucket: 'store_credit');       // 1450   — the int API is unchanged
$user->formatCreditsBalance('store_credit', 'en');   // "€14.50" (money's locale formatter)
$user->displayCreditsBalance('store_credit');        // "14.50"  (read at the EUR exponent)
```

| Method | Returns | Throws |
|---|---|---|
| `creditsCurrency(?string $bucket = null)` | `?Currency` — null when the bucket is not listed | `UnknownCurrency` (code not registered), `InvalidMoneyConfiguration` (malformed map) |
| `creditsBalanceMoney(?string $bucket = null, ?CarbonInterface $at = null)` | `Money` of the bucket currency — `Money::ofMinor(creditsBalance($at, $bucket), $currency)` | `BucketNotDenominatedException` |
| `modifyCreditsMoney(Money $amount, ?string $description = null, ?array $meta = null, bool $allowOverdraft = false, ?string $bucket = null)` | the new `Credit` row | `BucketNotDenominatedException`, `CurrencyMismatch`, `AmountOverflow`, `InsufficientCreditsException` |
| `formatCreditsBalance(?string $bucket = null, ?string $locale = null)` | `Money::format($locale)` of the balance | `BucketNotDenominatedException` |

- **One write path.** `modifyCreditsMoney()` checks the currency, converts with
  `Money::minorInt()` and calls `modifyCredits()` — the same overdraft guard, minimum balance,
  row lock and `CreditsModified` event. Every refusal happens **before** anything is written.
- **Currency identity is code + exponent** (money's `Currency::equals()`): a `USD` amount
  into an `EUR` bucket throws `CurrencyMismatch`.
- **`displayCreditsBalance()`** reads a denominated bucket at its currency exponent (and
  renders there unless `scale` overrides it). `displayCredits(int $amount)` takes no bucket,
  so it always uses `credits.scale`.
- **The default bucket** is denominated too when `credits.currencies` lists its name; the
  helpers then work without a `bucket` argument.
- **Custom currencies** come from money: `money.currencies.custom` in config, or
  `Currencies::register(Currency::custom('PTS', 0, 'Loyalty points'))` in your provider's
  `boot()`. Credits resolves the map **lazily**, on first use, so a registration in a provider
  that boots after credits' is accepted.
- **Precision limit.** The ledger column is a signed 64-bit integer, so a denominated bucket
  holds at most `9223372036854775807` minor units — irrelevant for fiat and points, but an
  18-decimal currency such as `ETH` tops out at about 9.22 units; denominate large crypto
  balances in a coarser custom unit (a lower exponent). An amount beyond the range throws
  money's `AmountOverflow` and writes nothing.

#### Recipe: redeem points for money

Credits does not convert between buckets, but money's exchange layer makes a fixed redemption
rate a two-liner — here 100 points = 1 EUR:

```php
use RoundlyConsulting\Money\Exchange\Converter;
use RoundlyConsulting\Money\Exchange\Providers\ArrayExchangeRateProvider;

$redemption = new Converter(new ArrayExchangeRateProvider(['PTS/EUR' => '0.01'], pivot: null));

$eur = $redemption->convert($user->creditsBalanceMoney('points'), 'EUR', rounding: \RoundingMode::TowardsZero);
// 1250 PTS → Money EUR 12.50
```

### Query scopes

The `Credit` model ships query scopes for reporting over the ledger:

```php
use RoundlyConsulting\Credits\Models\Credit;

Credit::query()->grants()->get();              // amount > 0
Credit::query()->deductions()->get();          // amount < 0
Credit::query()->upTo(now()->subWeek())->get(); // created_at <= $at
Credit::query()->forCreditable($user)->get();   // rows owned by $user
Credit::query()->bucket('promotional')->get();  // rows in a named bucket
Credit::query()->buckets(['promotional', 'purchased'])->get(); // rows in any listed bucket
```

### Events

A `RoundlyConsulting\Credits\Events\CreditsModified` event is dispatched after every recorded
change (no event fires when `setCreditsTo()` is a no-op). It carries the `creditable`, the new
`Credit` row, and the resulting `balance`.

```php
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Events\CreditsModified;

Event::listen(function (CreditsModified $event): void {
    // $event->creditable, $event->credit, $event->balance
});
```

### Actions (advanced usage)

The trait delegates to single-purpose actions you can resolve and call directly (for example
from a job or a service). Each takes a DTO / model and has a single `execute()` method:

```php
use RoundlyConsulting\Credits\Actions\ModifyCreditsAction;
use RoundlyConsulting\Credits\Actions\SetCreditsAction;
use RoundlyConsulting\Credits\Actions\GetCreditsBalanceAction;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;

app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(
    amount: -30,
    description: 'purchase',
    meta: ['order_id' => 42],
    allowOverdraft: false,
));

app(SetCreditsAction::class)->execute($user, 500, 'manual adjustment');
app(GetCreditsBalanceAction::class)->execute($user);
```

Bind your own implementation in the container to customise behaviour without forking.

### Console command

`credits:modify` applies a credit change in bulk to every entity resolved by the
`credits.modifiable` config. Register one or more resolver closures:

```php
// config/credits.php
use Closure;

'modifiable' => [
    function (Closure $modify): void {
        User::query()->each(fn (User $user) => $modify($user));
    },
],
```

Then run:

```bash
php artisan credits:modify --amount=10 --description="monthly bonus"
```

| Option | Default | Description |
|---|---|---|
| `--amount` | `0` | The credit amount to apply (must be an integer; may be negative). A non-integer value fails with a non-zero exit code. |
| `--description` | `null` | An optional human-readable description stored on each row. |
| `--bucket` | configured default | The named bucket to apply the change to. Omit to use the configured `default_bucket`. |
| `--allow-overdraft` | `false` | Permit deductions that drive the balance below zero. |

Resolved entities that are not `Creditable` models are skipped with a warning rather than
failing the run.

### Concurrency

The balance is never a stored column — it is the sum of an append-only ledger. A deduction
runs inside a transaction that first locks the owner's own row (`lockForUpdate()` on your
creditable model), then reads the ledger under `lockForUpdate()` before the overdraft guard
decides, so two racing debits of one owner serialise and the second cannot overdraw — also on
Postgres, where a locked ledger read that had to wait would otherwise decide against the
balance from before the racing debit, and also in an empty bucket with a negative
`minimum_balance`. The owner row is held only for that short transaction. Because every
change is written as a new delta row (never an absolute balance), a change that lands between
the balance read and the write is folded in, never lost.

### Inspecting the configuration

```bash
php artisan about --only=credits
```

Reports the resolved model, the overdraft policy, the minimum balance, the default bucket, the
scale, the display rounding mode (`INVALID` when `credits.rounding` names no mode), and how
many `modifiable` resolvers are registered (a count — never what they resolve).

## Upgrading

The display rounding mode moved from PHP's `PHP_ROUND_HALF_*` integers to PHP 8.4's native
`\RoundingMode` — `credits.rounding` is now a string and the `rounding` argument of
`displayCredits()` / `displayCreditsBalance()` (and `FormatCreditsAction::execute()`) is a
`?\RoundingMode`. The mapping is behaviour-identical, including for negative amounts:

| Before | `credits.rounding` | `rounding:` argument |
|---|---|---|
| `PHP_ROUND_HALF_UP` | `'half_away_from_zero'` | `\RoundingMode::HalfAwayFromZero` |
| `PHP_ROUND_HALF_DOWN` | `'half_towards_zero'` | `\RoundingMode::HalfTowardsZero` |
| `PHP_ROUND_HALF_EVEN` | `'half_even'` | `\RoundingMode::HalfEven` |
| `PHP_ROUND_HALF_ODD` | `'half_odd'` | `\RoundingMode::HalfOdd` |

A published config still holding a `PHP_ROUND_*` integer throws `InvalidMoneyConfiguration` on
the first display call — replace it with the string from the table.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security
vulnerabilities.

## Credits

- [Andrej Mihaliak](https://github.com/mihaliak)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
