<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

it('applies credits to entities resolved from config', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    config()->set('credits.modifiable', [
        function (Closure $modify) use ($user): void {
            $modify($user);
        },
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 75, '--description' => 'batch top-up']);

    $credit = $user->credits()->sole();

    expect($exitCode)->toBe(0)
        ->and($user->creditsBalance())->toBe(75)
        ->and($credit->description)->toBe('batch top-up')
        ->and($credit->meta)->toBe(['info' => 'Credits modified by credits:modify command.']);
});

it('applies a negative amount', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(100);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify($user),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => -40]);

    expect($exitCode)->toBe(0)
        ->and($user->creditsBalance())->toBe(60);
});

it('applies credits to multiple entities across resolvers', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $bob = User::query()->create(['name' => 'Bob']);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify($ada),
        fn (Closure $modify) => $modify($bob),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 10]);

    expect($exitCode)->toBe(0)
        ->and($ada->creditsBalance())->toBe(10)
        ->and($bob->creditsBalance())->toBe(10);
});

it('rejects a non-integer amount', function (): void {
    config()->set('credits.modifiable', []);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 'abc']);

    expect($exitCode)->toBe(1);
});

it('warns and skips a non-creditable resolved entity', function (): void {
    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify(new stdClass),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 10]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('not a Creditable');
});

it('supports overdraft via the flag', function (): void {
    $user = User::query()->create(['name' => 'Ada']);
    $user->modifyCredits(20);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify($user),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => -30, '--allow-overdraft' => true]);

    expect($exitCode)->toBe(0)
        ->and($user->creditsBalance())->toBe(-10);
});

it('applies credits to the named bucket from the --bucket option', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => $modify($user),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 30, '--bucket' => 'promotional']);

    expect($exitCode)->toBe(0)
        ->and($user->creditsBalance(bucket: 'promotional'))->toBe(30)
        ->and($user->creditsBalance())->toBe(0);
});

// `--amount` is required now (it used to default to 0, which this test relied on).
it('succeeds with no configured resolvers', function (): void {
    config()->set('credits.modifiable', []);

    $exitCode = Artisan::call('credits:modify', ['--amount' => 10]);

    expect($exitCode)->toBe(0);
});

// Regression: one entity with insufficient credits threw out of the run — the entities before
// it were charged, the ones after it were not, and no summary was printed, so a re-run charged
// the first ones twice.
it('refuses an entity with insufficient credits, finishes the run, and exits non-zero', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $bob = User::query()->create(['name' => 'Bob']);
    $cyd = User::query()->create(['name' => 'Cyd']);
    $ada->modifyCredits(100);
    $cyd->modifyCredits(100);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => User::query()->orderBy('id')->each(fn (User $user) => $modify($user)),
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => -10]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($ada->creditsBalance())->toBe(90)
        ->and($bob->creditsBalance())->toBe(0)
        ->and($cyd->creditsBalance())->toBe(90)
        ->and($output)->toContain('Refused '.User::class.' #'.$bob->id.': Insufficient credits')
        ->and($output)->toContain('Modified credits on 2 entities.')
        ->and($output)->toContain('Refused 1 entity with insufficient credits.');
});

it('reports every refused entity when a whole run is refused', function (): void {
    $ada = User::query()->create(['name' => 'Ada']);
    $bob = User::query()->create(['name' => 'Bob']);

    config()->set('credits.modifiable', [
        fn (Closure $modify) => [$modify($ada), $modify($bob)],
    ]);

    $exitCode = Artisan::call('credits:modify', ['--amount' => -1]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('Modified credits on 0 entities.')
        ->toContain('Refused 2 entities with insufficient credits.');
});

// Regression: `--amount` defaulted to 0, so a run without it (or with `--amount=0`) wrote a
// 0-amount ledger row and fired CreditsModified for every resolved entity, then reported success.
it('refuses a missing, blank or zero amount before any resolver runs', function (string|array $call, string $error): void {
    Event::fake([CreditsModified::class]);
    $user = User::query()->create(['name' => 'Ada']);
    $resolved = 0;

    config()->set('credits.modifiable', [
        function (Closure $modify) use ($user, &$resolved): void {
            $resolved++;
            $modify($user);
        },
    ]);

    $exitCode = is_string($call) ? Artisan::call($call) : Artisan::call('credits:modify', $call);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain($error)
        ->and($resolved)->toBe(0)
        ->and(Credit::query()->count())->toBe(0);

    Event::assertNotDispatched(CreditsModified::class);
})->with([
    'no --amount' => [[], 'The --amount option is required.'],
    'a bare --amount' => ['credits:modify --amount', 'The --amount option is required.'],
    'a blank --amount=' => ['credits:modify --amount=', 'The --amount option must be an integer.'],
    '--amount=0' => [['--amount' => '0'], 'The --amount option must not be 0.'],
    'an integer 0' => [['--amount' => 0], 'The --amount option must not be 0.'],
]);

it('still applies a non-zero amount', function (): void {
    $user = User::query()->create(['name' => 'Ada']);

    config()->set('credits.modifiable', [fn (Closure $modify) => $modify($user)]);

    expect(Artisan::call('credits:modify', ['--amount' => '10']))->toBe(0)
        ->and($user->creditsBalance())->toBe(10);
});
