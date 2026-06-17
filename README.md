# Credits for Laravel

Manage credits / wallet balance on any Eloquent entity. Each credit change is stored as an
immutable, time-stamped ledger row (positive to grant, negative to deduct), and an entity's
balance is the sum of its rows — optionally as of a point in time.

## Requirements

- PHP 8.3 or 8.4
- Laravel 12 or 13

## Installation

Install the package via Composer:

```bash
composer require roundly-consulting/credits-for-laravel
```

Publish and run the migrations:

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

    'allow_overdraft' => env('CREDITS_ALLOW_OVERDRAFT', false),

    'minimum_balance' => 0,

    'default_bucket' => 'default',

    'scale' => 0,

    'modifiable' => [
        //
    ],

];
```

| Key | Type | Default | Env | Purpose |
|---|---|---|---|---|
| `model` | `class-string<RoundlyConsulting\Credits\Models\Credit>` | `Credit::class` | — | The Eloquent model used to store credit rows. Override with your own subclass to customise behaviour or the table. |
| `allow_overdraft` | `bool` | `false` | `CREDITS_ALLOW_OVERDRAFT` | When `false`, a deduction that would take the balance below `minimum_balance` is rejected with an `InsufficientCreditsException`. Set `true` to permit negative balances globally. |
| `minimum_balance` | `int` | `0` | — | The floor enforced when overdraft is disallowed. |
| `default_bucket` | `string` | `'default'` | — | The bucket used for reads and writes when a call omits one. A bucket-less balance query returns this bucket's balance only — it does not sum across buckets. See [Named buckets](#named-buckets). |
| `scale` | `int` | `0` | — | Documents the integer-minor-unit convention for fractional credits. The package never multiplies by this; formatting happens in the host app. See [Fractional credits (scale)](#fractional-credits-scale). |
| `modifiable` | `array<Closure>` | `[]` | — | Resolvers invoked by the `credits:modify` command. Each closure receives a `$modify` callback that applies the requested change to a `Creditable` entity. |

The package ships with sensible defaults and works with **zero** host configuration.

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

### Fractional credits (scale)

Credits are stored as whole integers to avoid floating-point drift. To represent fractional
credits, treat the stored value as **minor units** and set `scale` in the config to the number
of decimal places they represent. For example, with `scale = 2` a stored value of `150`
represents `1.50` credits — store and deduct `150`, and multiply/format by `10 ** scale` when
displaying in your application:

```php
$scale = (int) config('credits.scale'); // 2

$user->modifyCredits(150, 'half a credit'); // stores 150 minor units

$display = $user->creditsBalance() / (10 ** $scale); // 1.5
```

The package never multiplies or divides by `scale`; it is a shared convention so every
consumer agrees on how the stored integers map to displayed values. Storage stays an integer
(`bigInteger`) and the core math is unchanged.

### Query scopes

The `Credit` model ships query scopes for reporting over the ledger:

```php
use RoundlyConsulting\Credits\Models\Credit;

Credit::query()->grants()->get();              // amount > 0
Credit::query()->deductions()->get();          // amount < 0
Credit::query()->upTo(now()->subWeek())->get(); // created_at <= $at
Credit::query()->forCreditable($user)->get();   // rows owned by $user
Credit::query()->bucket('promotional')->get();  // rows in a named bucket
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

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security
vulnerabilities.

## Credits

- [Andrej Mihaliak](https://github.com/mihaliak)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
