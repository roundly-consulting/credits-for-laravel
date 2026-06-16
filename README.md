# Credits for Laravel

Manage credits / wallet balance on any Eloquent entity. Each credit change is stored as an
immutable, time-stamped ledger row (positive to grant, negative to deduct), and an entity's
balance is the sum of its rows — optionally as of a point in time.

## Requirements

- PHP 8.3 or 8.4
- Laravel 11 or 12

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

## Configuration

The published config file lives at `config/credits.php`:

```php
<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Models\Credit;

return [

    'model' => Credit::class,

    'modifiable' => [
        //
    ],

];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `model` | `class-string<RoundlyConsulting\Credits\Models\Credit>` | `Credit::class` | The Eloquent model used to store credit rows. Override with your own subclass to customise behaviour or the table. |
| `modifiable` | `array<Closure>` | `[]` | Resolvers invoked by the `credits:modify` command. Each closure receives a `$modify` callback that applies the requested change to a `Creditable` entity. |

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

### Read the balance

```php
$user->creditsBalance();            // 70

// Balance as of a point in time:
$user->creditsBalance(now()->subWeek());
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
| `--amount` | `0` | The credit amount to apply (may be negative). |
| `--description` | `null` | An optional human-readable description stored on each row. |

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
