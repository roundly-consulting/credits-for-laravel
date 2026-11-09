<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Actions\GetCreditsHeldAction;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

it('refuses held amounts that add up past int64', function (): void {
    config()->set('credits.holds.enabled', true);
    $user = User::query()->create(['name' => 'Ada']);

    CreditHold::factory()->count(2)->create([
        'creditable_type' => $user->getMorphClass(),
        'creditable_id' => $user->id,
        'amount' => PHP_INT_MAX,
    ]);

    app(GetCreditsHeldAction::class)->execute($user);
})->throws(AmountOverflow::class, 'The credits held in bucket [default] add up to [18446744073709551614]');
