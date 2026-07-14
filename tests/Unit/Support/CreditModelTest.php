<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\Credits\Tests\Fixtures\CustomCredit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('resolves the packaged model by default', function (): void {
    expect(CreditModel::class())->toBe(Credit::class);
});

it('resolves a configured subclass of the packaged model', function (): void {
    config()->set('credits.model', CustomCredit::class);

    expect(CreditModel::class())->toBe(CustomCredit::class);
});

it('falls back to the packaged model when the configured model is not a credit', function (): void {
    config()->set('credits.model', User::class);

    expect(CreditModel::class())->toBe(Credit::class);
});

it('throws when the configured model is not an eloquent model', function (): void {
    config()->set('credits.model', 'not-a-model');

    expect(fn (): string => CreditModel::class())->toThrow(InvalidConfigurationException::class);
});
