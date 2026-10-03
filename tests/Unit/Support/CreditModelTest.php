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

it('refuses a foreign model instead of falling back to the packaged one', function (): void {
    // The toolkit refuses any class that is not the packaged model or a subclass of it.
    config()->set('credits.model', User::class);

    expect(fn (): string => CreditModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [credits.model] must be a class-string of ['.Credit::class.'], ['.User::class.'] given.',
    );
});

it('throws when the configured model is not an eloquent model', function (): void {
    config()->set('credits.model', 'not-a-model');

    expect(fn (): string => CreditModel::class())->toThrow(InvalidConfigurationException::class);
});
