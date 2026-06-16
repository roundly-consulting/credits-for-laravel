<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;

it('exposes all constructor fields', function (): void {
    $data = new CreditChangeData(
        amount: 50,
        description: 'bonus',
        meta: ['source' => 'promo'],
        allowOverdraft: true,
    );

    expect($data->amount)->toBe(50)
        ->and($data->description)->toBe('bonus')
        ->and($data->meta)->toBe(['source' => 'promo'])
        ->and($data->allowOverdraft)->toBeTrue();
});

it('defaults description, meta, and overdraft', function (): void {
    $data = new CreditChangeData(amount: 10);

    expect($data->description)->toBeNull()
        ->and($data->meta)->toBeNull()
        ->and($data->allowOverdraft)->toBeFalse();
});

it('maps to the persisted column attributes', function (): void {
    $data = new CreditChangeData(amount: 10, description: 'd', meta: ['a' => 1]);

    expect($data->toAttributes())->toBe([
        'amount' => 10,
        'description' => 'd',
        'meta' => ['a' => 1],
    ]);
});
