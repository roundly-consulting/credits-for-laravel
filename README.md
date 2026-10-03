<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/credits-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=credits-for-laravel">
    <img src="art/hero.png" alt="Credits for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/credits-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/credits-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/credits-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/credits-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/credits-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/credits-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=credits-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

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
`php artisan migrate` before publishing creates nothing. It creates two tables: `credits`, the
ledger, and `credit_locks`, one small row per owner that serialises concurrent changes (see
[Concurrency](#concurrency)):

```bash
php artisan vendor:publish --tag="credits-migrations"
php artisan migrate
```

The migration is forward-only: like every Roundly package migration it has no `down()`, so
`php artisan migrate:rollback` does not drop the two tables (and the next `migrate` then fails
on "table already exists"). The published copy is yours — to uninstall, drop `credits` and
`credit_locks` in a migration of your own, or add a `down()` to your copy.

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

    'key_type' => env('CREDITS_KEY_TYPE', 'bigint'),

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
| `key_type` | `string` | `'bigint'` | `CREDITS_KEY_TYPE` | The key type of **your creditable models** (the ones using `HasCredits`) — the `creditable_id` column: `bigint`, `uuid` or `ulid`; anything else throws an `InvalidConfigurationException`. Set it to `uuid`/`ulid` when those models use `HasUuids`/`HasUlids`. Fixed when the migration first runs. See [Key types](#key-types). |
| `primary_key_type` | `string` | `'bigint'` | `CREDITS_PRIMARY_KEY_TYPE` | The primary-key strategy of the `credits` table: `bigint`, `uuid` or `ulid`. Fixed when the migration first runs. See [Key types](#key-types). |
| `allow_overdraft` | `bool` | `false` | `CREDITS_ALLOW_OVERDRAFT` | When `false`, a deduction that would take the balance below `minimum_balance` is rejected with an `InsufficientCreditsException`. Set `true` to permit deductions below `minimum_balance` globally. The env value is read as a boolean: `1`, `true`, `on` and `yes` enable it; `0`, `false`, `off` and `no` leave it off; anything else throws an `InvalidConfigurationException` naming the key. |
| `minimum_balance` | `int` | `0` | — | The floor enforced when overdraft is disallowed. An `int` or a canonical integer string (`"50"`, `"-100"`); blank (`""`) is not set, so `0` applies; anything else (`"fifty"`, `"50.5"`) throws an `InvalidConfigurationException` naming the key instead of becoming `0`. |
| `default_bucket` | `string` | `'default'` | — | The bucket used for reads and writes when a call omits one. A bucket-less balance query returns this bucket's balance only — it does not sum across buckets. See [Named buckets](#named-buckets). Blank is not set, so `'default'` applies; a non-string value throws an `InvalidConfigurationException` naming the key. |
| `scale` | `int` | `0` | — | The number of decimal places the stored integer encodes (the integer-minor-unit convention for fractional credits). Used by the display helpers; a [currency-denominated bucket](#currency-denominated-buckets) ignores it (its scale is the currency exponent). See [Displaying balances](#displaying-balances). An integer `0`–`36` (or its canonical string); anything else throws an `InvalidConfigurationException` naming the key. |
| `rounding` | `string` | `'half_away_from_zero'` | `CREDITS_ROUNDING` | Default rounding mode for the display helpers when a requested display scale is smaller than the stored scale: a PHP 8.4 `\RoundingMode` case in snake_case — `half_away_from_zero`, `half_towards_zero`, `half_even` (banker's), `half_odd`, `towards_zero`, `away_from_zero`, `positive_infinity`, `negative_infinity`. Not set (absent, `null` or a blank `CREDITS_ROUNDING=`) means `half_away_from_zero`; an unknown value (such as a `PHP_ROUND_*` integer) throws `InvalidMoneyConfiguration` on first use. Overridable per call with a `\RoundingMode`. |
| `currencies` | `array<string, string>` | `[]` | — | Bucket name → money currency code (ISO or custom). A listed bucket's integers are minor units of that currency. Resolved lazily on first use. See [Currency-denominated buckets](#currency-denominated-buckets). |
| `modifiable` | `array<Closure>` | `[]` | — | Resolvers invoked by the `credits:modify` command. Each closure receives a `$modify` callback that applies the requested change to a `Creditable` entity. A non-array value or a non-callable entry throws an `InvalidConfigurationException` naming the key. |

The package ships with sensible defaults and works with **zero** host configuration.

### Key types

Credits has two independent key types, and both are read when the migration runs — choose
them **before** you publish and migrate.

**`key_type`** is the key type of the models that **hold** credits. It types the
`creditable_id` column (of both `credits` and `credit_locks`), so it must match your
creditable models' primary key: keep the default `bigint` for auto-incrementing ids, and set
`uuid` or `ulid` when those models use `HasUuids` or `HasUlids`:

```dotenv
CREDITS_KEY_TYPE=uuid
```

Leave it at `bigint` with uuid-keyed users and PostgreSQL rejects the first change:

```
SQLSTATE[22P02]: invalid input syntax for type bigint: "01a0e9ca-cf2c-7..."
```

Every creditable model in the application must share that one key type.

**`primary_key_type`** sets the type of the `credits` table's own `id` column, and the model
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

Add the `HasCredits` trait to any Eloquent model and implement the `Creditable` interface. The
interface is required: every API takes the owner as a `Model&Creditable`, so a model with only
the trait fails with a `TypeError` on first use (and static analysis flags it before that).

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Traits\HasCredits;

final class User extends Model implements Creditable
{
    use HasCredits;
}
```

### The `Credits` facade

`Credits::for($owner)` is the entry point for everything the package does with one owner's
credits. The facade is also auto-registered as the global alias `Credits`. It returns an immutable scope: `bucket()` and `allowOverdraft()` each return a new
scope, so you can keep and reuse one.

```php
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Money\Money;

Credits::for($user)->add(100, 'Welcome');                       // Credit row
Credits::for($user)->deduct(30, 'Order #42', ['order_id' => 42]); // InsufficientCreditsException below the floor
Credits::for($user)->modify(-5);                                 // a signed change
Credits::for($user)->allowOverdraft()->deduct(500);              // may go below the floor
Credits::for($user)->setTo(0, 'Reset');                          // one delta row; null when already there

Credits::for($user)->balance();                                  // int — the default bucket
Credits::for($user)->balance(now()->subWeek());                  // as of a point in time
Credits::for($user)->has(50);                                    // bool
Credits::for($user)->total();                                    // int — every bucket

$points = Credits::for($user)->bucket('points');
$points->add(100, 'Welcome');
$points->balance();                                              // 100

Credits::for($user)->buckets(['promotional', 'purchased'])->balance(); // summed, read-only
Credits::for($user)->buckets(['promotional', 'purchased'])->has(50);

// A currency-denominated bucket (see below) reads and writes Money:
$store = Credits::for($user)->bucket('store_credit');
$store->addMoney(Money::ofMajor('25.00', 'EUR'), 'Gift card');
$store->deductMoney(Money::ofMinor(1050, 'EUR'), 'Order #42');
$store->money();                                                 // Money EUR 14.50
$store->currency();                                              // Currency EUR
$store->format();                                                // "14.50"
$store->formatMoney('en');                                       // "€14.50"

// Owner-free helpers:
Credits::format(1250, scale: 2);                                 // "1250.00" at credits.scale = 0
Credits::currency('store_credit');                               // ?Currency
```

`add()` and `deduct()` take a non-negative amount and throw an `InvalidArgumentException`
otherwise. Use `modify()` for a signed change. The same holds for `addMoney()` /
`deductMoney()` / `modifyMoney()`. `buckets([...])` only reads, because every write names
exactly one bucket.

The model methods below (`$user->modifyCredits()`, `$user->creditsBalance()`, …) are shorthand
for the same calls: each one goes through `Credits::for($user)`.

### Without the facade

The facade is a thin layer over `CreditsManager`. Inject the manager to get the same API without
the facade:

```php
use RoundlyConsulting\Credits\CreditsManager;

final class RewardSignup
{
    public function __construct(private CreditsManager $credits) {}

    public function __invoke(User $user): void
    {
        $this->credits->for($user)->bucket('points')->add(100, 'Welcome');
    }
}
```

The manager also has flat verbs that every scope ends in. They are handy for jobs that already
hold a `CreditChangeData`:

```php
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;

$credits->modify($user, new CreditChangeData(amount: -30, description: 'purchase', bucket: 'points'));
$credits->setTo($user, 500, 'manual adjustment');
$credits->balance($user, bucket: 'points');
$credits->total($user, ['promotional', 'purchased']);           // null = every bucket
```

Each operation is also a single-purpose action with one `execute()` method, for when you want
to resolve and run it yourself:

```php
use RoundlyConsulting\Credits\Actions\{FormatCreditsAction, GetCreditsBalanceAction,
    GetCreditsTotalAction, ModifyCreditsAction, ResolveBucketCurrencyAction, SetCreditsAction};

app(ModifyCreditsAction::class)->execute($user, new CreditChangeData(amount: -30, description: 'purchase'));
app(SetCreditsAction::class)->execute($user, 500, 'manual adjustment');
app(GetCreditsBalanceAction::class)->execute($user, bucket: 'points');
app(GetCreditsTotalAction::class)->execute($user, ['promotional', 'purchased']);
app(FormatCreditsAction::class)->execute(123450, scale: 2);
app(ResolveBucketCurrencyAction::class)->execute('store_credit');
```

To change the behaviour without forking, bind your own implementation of an action in the
container. The manager resolves each action every time it is called.

### Testing with the fake

`Credits::fake()` swaps the manager with a recorder that writes no ledger row and fires no event.
The swap covers the facade, every injected `CreditsManager`, the `HasCredits` model methods and
`credits:modify`:

```php
use RoundlyConsulting\Credits\Facades\Credits;

$fake = Credits::fake();

// ... run the code under test ...

$fake->assertAdded($user, 100, bucket: 'points');   // amount and bucket optional
$fake->assertDeducted($user, 30);                   // the positive amount, as passed to deduct()
$fake->assertSet($user, 0);                         // the requested target
$fake->assertNothingAdded();
$fake->assertNothingDeducted();
$fake->assertNothingSet();
$fake->assertNothingModified();                     // no add, deduct or set at all
```

The fake keeps an in-memory ledger. Balances add it to the owner's real rows, so a grant made on
the fake can be spent on the fake. The overdraft guard still applies: a deduction the real
manager would refuse throws `InsufficientCreditsException` and is not recorded. `setTo()` is
recorded even when the balance already matches.

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

The read-then-write runs in one database transaction that serialises on the owner (see
[Concurrency](#concurrency)), so concurrent deductions cannot both breach the floor — on any
isolation level.

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
row (it does nothing if the balance already matches). It takes the owner lock and reads the
balance under a row lock, in one transaction with the write, so two racing calls serialise
(see [Concurrency](#concurrency)): the second computes its delta from the balance the first
left behind.

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
argument, the `credits:modify` command exposes a `--bucket=` option, and the facade form is
`Credits::for($user)->bucket('promotional')`.

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

The reusable formatting core is `Credits::format()` — `RoundlyConsulting\Credits\Actions\FormatCreditsAction`
(`execute(int $amount, ?int $scale = null, ?\RoundingMode $rounding = null, ?int $storedScale = null): string`),
which the trait methods and `Credits::for($user)->format()` delegate to. It needs no model. It is a thin layer over money-for-laravel's
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
| `creditsCurrency(?string $bucket = null)` | `?Currency` — null when the bucket is not listed (or mapped to a blank code) | `UnknownCurrency` (code not registered), `InvalidMoneyConfiguration` (malformed map) |
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
`Credit` row, and the resulting `balance` of the row's bucket — the balance this change
produced, read under the owner lock inside the change's transaction, so a racing change never
leaks into it.

```php
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Events\CreditsModified;

Event::listen(function (CreditsModified $event): void {
    // $event->creditable, $event->credit, $event->balance
});
```

### Console command

`credits:modify` applies a credit change in bulk to every entity resolved by the
`credits.modifiable` config, through `Credits::for($entity)` (so `Credits::fake()` records it). Register one or more resolver closures:

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
| `--allow-overdraft` | `false` | Permit deductions that drive the balance below `minimum_balance` (which may be other than zero). |

Resolved entities that are not `Creditable` models are skipped with a warning rather than
failing the run. An entity whose deduction the overdraft guard refuses is skipped too — the
run carries on with the rest, printing `Refused App\Models\User #2: Insufficient credits: …`
for each — and once every entity has been visited the command reports
`Refused N entities with insufficient credits.` and exits non-zero. Each change is its own
transaction, so the entities that were charged stay charged: re-run only for the refused ones
(for example with a resolver that selects them), not the whole set.

### Concurrency

The balance is never a stored column — it is the sum of an append-only ledger. Every change
(`modify`, `add`, `deduct`, `setTo`, and their Money forms) runs in one transaction that first
takes the owner's lock — an `UPDATE` of the owner's row in `credit_locks` — then reads the
bucket's ledger rows under `lockForUpdate()`, decides (the overdraft guard for a debit, the
delta for `setCreditsTo()`), and appends the new row. Racing changes of one owner therefore
serialise on every isolation level:

- **READ COMMITTED** (the Postgres default): the second change waits for the first, then reads
  the balance the first left behind — also in an empty bucket with a negative
  `minimum_balance`.
- **MySQL / MariaDB REPEATABLE READ** (their default): the lock and the ledger read are
  current reads, so they see the first change's committed row too.
- **Postgres REPEATABLE READ / SERIALIZABLE**: the second change's snapshot predates its wait,
  so rather than decide on stale data it fails with a serialisation failure
  (SQLSTATE 40001), and the package retries its transaction — up to 5 attempts — with a fresh
  snapshot.

Inside **your own** `DB::transaction()`, the snapshot belongs to your transaction, so the
package cannot retry for you: on Postgres REPEATABLE READ / SERIALIZABLE a racing change
surfaces as `Illuminate\Database\DeadlockException` (SQLSTATE 40001) and nothing is written.
Retry the whole transaction — `DB::transaction($callback, attempts: 3)` does not, because the
nested exception no longer carries the SQLSTATE Laravel's retry checks for:

```php
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Facades\DB;

retry(3, fn () => DB::transaction(function () use ($user): void {
    // … your own reads and writes …
    $user->modifyCredits(-30, 'purchase');
}), when: fn (Throwable $e): bool => $e instanceof DeadlockException);
```

The lock is held only for that short transaction. Because every change is written as a new
delta row (never an absolute balance), a change is never lost, and `credit_locks` holds a
version counter only — the ledger stays the single source of truth.

### Inspecting the configuration

```bash
php artisan about --only=credits
```

Reports the resolved model, the primary key type (`primary_key_type`), the creditable key type
(`key_type`), the overdraft policy (`ALLOWED` or `BLOCKED`, read from `allow_overdraft` the
same way the guard reads it), the minimum balance, the default bucket, the scale, the display
rounding mode (`INVALID` when `credits.rounding` names no mode), and how many `modifiable`
resolvers are registered (a count — never what they resolve).

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

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=credits-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=credits-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
