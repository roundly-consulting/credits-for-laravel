<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Credits\Facades\Credits;
use RoundlyConsulting\Credits\Tests\Fixtures\User;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-09 23:30:00');

    $this->ada = User::query()->create(['name' => 'Ada']);
    $this->bob = User::query()->create(['name' => 'Bob']);
    $this->cyd = User::query()->create(['name' => 'Cyd']);

    $this->ada->modifyCredits(70, bucket: 'free');
    $this->bob->modifyCredits(5, bucket: 'free');
    $this->bob->modifyCredits(9, bucket: 'paid');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Relation::morphMap([], false);
});

it('resets every owner with rows in the bucket, once per period', function (): void {
    $this->artisan('credits:reset', ['bucket' => ['free'], '--to' => 100])
        ->expectsOutputToContain('Reset 2, already reset in period [2026-11] 0.')
        ->assertSuccessful();

    $this->artisan('credits:reset', ['bucket' => ['free'], '--to' => 100])
        ->expectsOutputToContain('Reset 0, already reset in period [2026-11] 2.')
        ->assertSuccessful();

    expect($this->ada->creditsBalance(bucket: 'free'))->toBe(100)
        ->and($this->bob->creditsBalance(bucket: 'free'))->toBe(100)
        ->and($this->bob->creditsBalance(bucket: 'paid'))->toBe(9)
        ->and($this->cyd->creditsBalance(bucket: 'free'))->toBe(0);
});

it('resets several buckets in one run', function (): void {
    $this->artisan('credits:reset', ['bucket' => ['free', 'paid', 'free'], '--period' => 'daily'])
        ->expectsOutputToContain('Reset 3, already reset in period [2026-11-09] 0.')
        ->assertSuccessful();

    expect($this->bob->totalCreditsBalance())->toBe(0);
});

it('reads the period in the given timezone', function (): void {
    $this->artisan('credits:reset', ['bucket' => ['free'], '--period' => 'daily', '--timezone' => 'Europe/Bratislava'])
        ->expectsOutputToContain('[2026-11-10]')
        ->assertSuccessful();
});

it('takes an explicit period key and a description', function (): void {
    $this->artisan('credits:reset', ['bucket' => ['free'], '--key' => 'cycle-1', '--description' => 'Cycle reset'])
        ->expectsOutputToContain('[cycle-1]')
        ->assertSuccessful();

    expect($this->ada->credits()->latest('id')->first()?->description)->toBe('Cycle reset');
});

it('resets the owners the resolvers yield', function (): void {
    config()->set('credits.modifiable', [
        function (Closure $reset): void {
            $reset($this->cyd);
            $reset('not a model');
        },
    ]);

    $this->artisan('credits:reset', ['bucket' => ['free'], '--to' => 25, '--resolvers' => true])
        ->expectsOutputToContain('Skipped a resolved entity that is not a Creditable model.')
        ->expectsOutputToContain('Reset 1, already reset in period [2026-11] 0.')
        ->assertSuccessful();

    expect($this->cyd->creditsBalance(bucket: 'free'))->toBe(25)
        ->and($this->ada->creditsBalance(bucket: 'free'))->toBe(70);
});

it('skips an owner that no longer exists', function (): void {
    $this->bob->delete();

    $this->artisan('credits:reset', ['bucket' => ['free']])
        ->expectsOutputToContain('the owner no longer exists')
        ->expectsOutputToContain('Reset 1,')
        ->assertSuccessful();
});

it('skips a ledger row whose owner type is no model', function (): void {
    DB::table('credits')->insert(['creditable_type' => 'gone-type', 'creditable_id' => 9, 'bucket' => 'free', 'amount' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('credits:reset', ['bucket' => ['free']])
        ->expectsOutputToContain('Skipped gone-type #9: the owner no longer exists.')
        ->assertSuccessful();
});

it('loads owners through the morph map', function (): void {
    Relation::morphMap(['user' => User::class], false);
    $dana = User::query()->create(['name' => 'Dana']);
    $dana->modifyCredits(3, bucket: 'free');

    $this->artisan('credits:reset', ['bucket' => ['free']])
        ->expectsOutputToContain('Reset 3,')
        ->assertSuccessful();

    expect($dana->creditsBalance(bucket: 'free'))->toBe(0);
});

it('reports a refused owner, resets the rest and fails', function (): void {
    config()->set('credits.holds.enabled', true);
    Credits::for($this->ada)->bucket('free')->hold(50);

    $this->artisan('credits:reset', ['bucket' => ['free']])
        ->expectsOutputToContain('#'.$this->ada->id.' [free]: Insufficient credits')
        ->expectsOutputToContain('Reset 1,')
        ->expectsOutputToContain('Refused 1 with insufficient credits.')
        ->assertFailed();

    expect(DB::table('credit_resets')->count())->toBe(1);

    $this->artisan('credits:reset', ['bucket' => ['free'], '--allow-overdraft' => true])
        ->expectsOutputToContain('Reset 1, already reset in period [2026-11] 1.')
        ->assertSuccessful();
});

it('refuses bad options before resetting anything', function (array $options, string $error): void {
    $this->artisan('credits:reset', ['bucket' => ['free'], ...$options])
        ->expectsOutputToContain($error)
        ->assertFailed();

    expect(DB::table('credit_resets')->count())->toBe(0);
})->with([
    'unknown period' => [['--period' => 'hourly'], 'The --period option must be one of: daily, weekly, monthly, yearly.'],
    'non-integer to' => [['--to' => '1.5'], 'The --to option must be an integer.'],
    'overflowing to' => [['--to' => '99999999999999999999'], 'The --to option must be an integer.'],
    'oversized key' => [['--key' => str_repeat('k', 65)], 'A reset period key must be 1 to 64 characters'],
    'unknown timezone' => [['--timezone' => 'Mars/Olympus'], 'Mars/Olympus'],
]);

it('goes through the facade, so a fake records it', function (): void {
    $fake = Credits::fake();

    $this->artisan('credits:reset', ['bucket' => ['free']])->assertSuccessful();

    $fake->assertReset($this->ada, 'free', '2026-11');
    expect(DB::table('credit_resets')->count())->toBe(0);
});
