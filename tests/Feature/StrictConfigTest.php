<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Owner rule: a typo in a host's config fails loudly and never falls back silently. A junk
 * floor used to cast to 0 (letting every deduction down to zero through), a junk scale to 0,
 * and a non-list resolver config to "nothing registered". A blank value (`''` or whitespace —
 * a host's `KEY=`) is not junk: it is not set, so the default applies.
 */
beforeEach(function (): void {
    $this->user = User::query()->create(['name' => 'Ada']);
});

it('refuses a junk minimum balance instead of casting it to 0 (strict config)', function (mixed $value): void {
    $this->user->modifyCredits(100);
    config()->set('credits.minimum_balance', $value);

    expect(fn () => Credits::for($this->user)->deduct(10))
        ->toThrow(InvalidConfigurationException::class, '[credits.minimum_balance]');
})->with([
    'word' => 'fifty',
    'decimal' => '50.5',
    'exponent' => '1e3',
    'bool' => true,
]);

it('keeps a zero floor when the minimum balance is blank (strict config)', function (string $blank): void {
    $this->user->modifyCredits(10);
    config()->set('credits.minimum_balance', $blank);

    Credits::for($this->user)->deduct(10);

    expect(Credits::for($this->user)->balance())->toBe(0)
        ->and(fn () => Credits::for($this->user)->deduct(1))->toThrow(InsufficientCreditsException::class);
})->with(['empty' => '', 'whitespace' => '  ']);

it('enforces a canonical integer-string minimum balance from env (strict config)', function (): void {
    $this->user->modifyCredits(100);
    config()->set('credits.minimum_balance', '50');

    expect(fn () => Credits::for($this->user)->deduct(60))->toThrow(InsufficientCreditsException::class);

    Credits::for($this->user)->deduct(50);

    expect(Credits::for($this->user)->balance())->toBe(50);
});

it('keeps a zero floor when the minimum balance is absent (strict config)', function (): void {
    $this->user->modifyCredits(10);
    config()->set('credits.minimum_balance', null);

    expect(fn () => Credits::for($this->user)->deduct(11))->toThrow(InsufficientCreditsException::class);
});

it('refuses a junk or out-of-range scale (strict config)', function (mixed $value): void {
    config()->set('credits.scale', $value);

    expect(fn () => $this->user->displayCredits(150))
        ->toThrow(InvalidConfigurationException::class, '[credits.scale]');
})->with([
    'word' => 'two',
    'decimal' => '2.5',
    'negative' => -1,
    'beyond the money cap' => 37,
]);

it('reads a canonical integer-string scale (strict config)', function (): void {
    $this->user->modifyCredits(150);
    config()->set('credits.scale', '2');

    expect($this->user->displayCreditsBalance())->toBe('1.50');
});

it('refuses a wrong-typed default bucket (strict config)', function (mixed $value): void {
    config()->set('credits.default_bucket', $value);

    expect(fn () => Credits::for($this->user)->balance())
        ->toThrow(InvalidConfigurationException::class, '[credits.default_bucket]');
})->with([
    'array' => [['default']],
    'int' => 5,
]);

it('uses the default bucket when the key is not set (strict config)', function (?string $value): void {
    config()->set('credits.default_bucket', $value);

    Credits::for($this->user)->add(5);

    expect($this->user->credits()->sole()->bucket)->toBe('default');
})->with(['absent' => null, 'empty' => '', 'whitespace' => '  ']);

it('reads a blank modifiable config as no resolvers (strict config)', function (): void {
    config()->set('credits.modifiable', '');

    expect(Artisan::call('credits:modify', ['--amount' => 10]))->toBe(0);
});

it('refuses a non-list modifiable config instead of modifying nothing (strict config)', function (mixed $value): void {
    config()->set('credits.modifiable', $value);

    expect(fn () => Artisan::call('credits:modify', ['--amount' => 10]))
        ->toThrow(InvalidConfigurationException::class, '[credits.modifiable]');
})->with([
    'a class string' => 'App\\Credits\\Resolver',
    'a non-callable entry' => [['not-a-function']],
]);

it('reports a broken setting as INVALID in about (strict config)', function (): void {
    config()->set('credits.minimum_balance', 'fifty');
    config()->set('credits.scale', 'two');
    config()->set('credits.default_bucket', ['x']);
    config()->set('credits.modifiable', 'nope');

    Artisan::call('about', ['--only' => 'credits']);

    expect(Artisan::output())
        ->toMatch('/Minimum balance\s*\.*\s*INVALID/')
        ->toMatch('/Default bucket\s*\.*\s*INVALID/')
        ->toMatch('/Scale\s*\.*\s*INVALID/')
        ->toMatch('/Modifiable resolvers\s*\.*\s*INVALID/');
});

it('reports a broken model, key type or overdraft setting as INVALID in about (strict config)', function (string $key, mixed $value, string $row): void {
    config()->set($key, $value);

    $exitCode = Artisan::call('about', ['--only' => 'credits']);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toMatch('/'.preg_quote($row, '/').'\s*\.*\s*INVALID/');
})->with([
    'overdraft' => ['credits.allow_overdraft', 'enabled', 'Overdraft'],
    'creditable key type' => ['credits.key_type', 'int', 'Creditable key type'],
    'primary key type' => ['credits.primary_key_type', 'serial', 'Primary key type'],
    'model' => ['credits.model', stdClass::class, 'Model'],
]);
