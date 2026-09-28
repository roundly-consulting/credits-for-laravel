<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Credits\Commands\ModifyCreditsCommand;
use RoundlyConsulting\Credits\CreditsServiceProvider;
use RoundlyConsulting\Credits\Models\Credit;

it('merges the package config', function (): void {
    expect(config('credits.model'))->toBe(Credit::class)
        ->and(config('credits.allow_overdraft'))->toBeFalse()
        ->and(config('credits.minimum_balance'))->toBe(0);
});

it('registers every publish tag', function (string $tag): void {
    expect(ServiceProvider::pathsToPublish(CreditsServiceProvider::class, $tag))->not->toBeEmpty();
})->with([
    'credits-config',
    'credits-migrations',
    'credits-translations',
]);

it('publishes its migration timestamp-injected into the host', function (): void {
    $paths = ServiceProvider::pathsToPublish(CreditsServiceProvider::class, 'credits-migrations');

    expect($paths)->toHaveCount(1);

    $source = (string) array_key_first($paths);
    $target = (string) reset($paths);

    expect(basename($source))->toBe('create_credits_table.php')
        ->and(dirname($target))->toBe(database_path('migrations'))
        ->and(basename($target))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_credits_table\.php$/');
});

it('never auto-loads its migrations — the host must publish them', function (): void {
    $registered = array_map(
        static fn (string $path): string => realpath($path) ?: $path,
        app('migrator')->paths(),
    );

    expect($registered)->not->toContain(realpath(__DIR__.'/../../database/migrations'));
});

it('registers the modify command', function (): void {
    expect(Artisan::all())->toHaveKey('credits:modify')
        ->and(Artisan::all()['credits:modify'])->toBeInstanceOf(ModifyCreditsCommand::class);
});

it('registers the package translations', function (): void {
    expect(trans('credits::messages.insufficient', ['requested' => 5, 'available' => 2]))
        ->toContain('5')
        ->toContain('2');
});

it('contributes a credits section to about', function (string $expected): void {
    $this->artisan('about --only=credits')
        ->expectsOutputToContain($expected)
        ->assertExitCode(0);
})->with([
    'Credits',
    'Model',
    'Overdraft',
    'Minimum balance',
    'Default bucket',
    'Scale',
    'Modifiable resolvers',
]);

it('reports overdraft as allowed in about when configured', function (): void {
    config()->set('credits.allow_overdraft', true);

    $this->artisan('about --only=credits')
        ->expectsOutputToContain('ALLOWED')
        ->assertExitCode(0);
});

it('reports the modifiable resolvers as a count, never what they resolve', function (): void {
    config()->set('credits.modifiable', [
        static fn (): null => null,
    ]);

    $this->artisan('about --only=credits')
        ->expectsOutputToContain('1 registered')
        ->assertExitCode(0);
});

it('reports no modifiable resolvers by default in about', function (): void {
    $this->artisan('about --only=credits')
        ->expectsOutputToContain('NONE')
        ->assertExitCode(0);
});

it('reports the overdraft policy the guard applies, for env strings too', function (mixed $value, string $expected): void {
    config()->set('credits.allow_overdraft', $value);

    $this->artisan('about --only=credits')
        ->expectsOutputToContain($expected)
        ->assertExitCode(0);
})->with([
    '"1"' => ['1', 'ALLOWED'],
    '"yes"' => ['yes', 'ALLOWED'],
    '"off"' => ['off', 'BLOCKED'],
    '"false"' => ['false', 'BLOCKED'],
]);
