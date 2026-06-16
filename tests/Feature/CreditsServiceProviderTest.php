<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Credits\Commands\ModifyCreditsCommand;
use RoundlyConsulting\Credits\Models\Credit;

it('merges the package config', function (): void {
    expect(config('credits.model'))->toBe(Credit::class)
        ->and(config('credits.allow_overdraft'))->toBeFalse()
        ->and(config('credits.minimum_balance'))->toBe(0);
});

it('loads the credits migration', function (): void {
    expect(Schema::hasTable('credits'))->toBeTrue();
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
