<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\BucketNotDenominatedException;
use RoundlyConsulting\Credits\Exceptions\CreditsException;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Tests\Fixtures\User;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;
use RoundlyConsulting\Money\Facades\Currencies;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    config()->set('credits.currencies', [
        'store_credit' => 'EUR',
        'points' => 'PTS',
        'crypto' => 'eth',
    ]);

    $this->user = User::query()->create(['name' => 'Ada']);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

describe('creditsCurrency', function (): void {
    it('resolves a mapped bucket to its money currency', function (): void {
        expect($this->user->creditsCurrency('store_credit'))->toBeInstanceOf(Currency::class)
            ->and($this->user->creditsCurrency('store_credit')?->equals(Currency::of('EUR')))->toBeTrue()
            ->and($this->user->creditsCurrency('points')?->code)->toBe('PTS')
            ->and($this->user->creditsCurrency('points')?->exponent)->toBe(0)
            // Codes are looked up case-insensitively, exactly like Currency::of().
            ->and($this->user->creditsCurrency('crypto')?->code)->toBe('ETH');
    });

    it('returns null for a plain-credits bucket', function (): void {
        expect($this->user->creditsCurrency('promotional'))->toBeNull()
            ->and($this->user->creditsCurrency())->toBeNull();
    });

    it('reads the default bucket when no bucket is given', function (): void {
        config()->set('credits.currencies', ['default' => 'EUR']);

        expect($this->user->creditsCurrency()?->code)->toBe('EUR');
    });

    it('refuses a currency code the registry does not know', function (): void {
        config()->set('credits.currencies', ['gift' => 'XYZ']);

        $this->user->creditsCurrency('gift');
    })->throws(UnknownCurrency::class, '[XYZ]');

    it('validates lazily, so a currency registered after credits booted is accepted', function (): void {
        config()->set('credits.currencies', ['gems' => 'GEM']);

        // Not registered yet — a boot-time check would have rejected this config for good.
        expect(fn () => $this->user->creditsCurrency('gems'))->toThrow(UnknownCurrency::class);

        // A host's provider registers it in its own boot(), after credits' provider.
        Currencies::register(Currency::custom('GEM', 0, 'Gems'));

        $this->user->modifyCreditsMoney(Money::ofMinor(7, 'GEM'), bucket: 'gems');

        expect($this->user->creditsCurrency('gems')?->code)->toBe('GEM')
            ->and($this->user->creditsBalanceMoney('gems')->equals(Money::ofMinor(7, 'GEM')))->toBeTrue();
    });

    it('refuses a currencies map that is not an array', function (): void {
        config()->set('credits.currencies', 'EUR');

        $this->user->creditsCurrency('store_credit');
    })->throws(InvalidMoneyConfiguration::class, '[credits.currencies]');

    it('refuses a bucket mapped to something other than a code string', function (mixed $code): void {
        config()->set('credits.currencies', ['store_credit' => $code]);

        $this->user->creditsCurrency('store_credit');
    })->with([
        'integer' => [978],
        'array' => [['EUR']],
    ])->throws(InvalidMoneyConfiguration::class, '[credits.currencies.store_credit]');

    it('reads a blank code or map as not set, leaving a plain-credits bucket', function (mixed $currencies): void {
        config()->set('credits.currencies', $currencies);

        expect($this->user->creditsCurrency('store_credit'))->toBeNull();
    })->with([
        'blank code' => [['store_credit' => '']],
        'whitespace code' => [['store_credit' => '  ']],
        'blank map' => [''],
    ]);
});

describe('creditsBalanceMoney', function (): void {
    it('reads the integer balance as minor units of the bucket currency', function (): void {
        $this->user->modifyCredits(1000, bucket: 'store_credit');
        $this->user->modifyCredits(50, bucket: 'store_credit');

        $balance = $this->user->creditsBalanceMoney('store_credit');

        expect($balance->equals(Money::ofMinor('1050', 'EUR')))->toBeTrue()
            // Money amounts are arbitrary-precision strings — compared as strings, never int.
            ->and($balance->minor())->toBe('1050')
            ->and($balance->toDecimal())->toBe('10.50')
            ->and($this->user->creditsBalance(bucket: 'store_credit'))->toBe(1050);
    });

    it('returns a zero money for an empty denominated bucket', function (): void {
        expect($this->user->creditsBalanceMoney('store_credit')->isZero())->toBeTrue()
            ->and($this->user->creditsBalanceMoney('store_credit')->currency()->code)->toBe('EUR');
    });

    it('isolates the denominated bucket from every other bucket', function (): void {
        $this->user->modifyCredits(999, bucket: 'promotional');
        $this->user->modifyCredits(250, bucket: 'points');

        expect($this->user->creditsBalanceMoney('points')->equals(Money::ofMinor(250, 'PTS')))->toBeTrue()
            ->and($this->user->creditsBalanceMoney('store_credit')->isZero())->toBeTrue();
    });

    it('honours the point in time', function (): void {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->user->modifyCredits(500, bucket: 'store_credit');

        Carbon::setTestNow('2026-01-02 10:00:00');
        $this->user->modifyCredits(300, bucket: 'store_credit');

        expect($this->user->creditsBalanceMoney('store_credit', Carbon::parse('2026-01-01 12:00:00'))->minor())->toBe('500')
            ->and($this->user->creditsBalanceMoney('store_credit')->minor())->toBe('800');
    });

    it('refuses a plain-credits bucket', function (): void {
        $this->user->creditsBalanceMoney('promotional');
    })->throws(BucketNotDenominatedException::class, '[promotional]');

    it('names the default bucket when an undenominated call omits it', function (): void {
        try {
            $this->user->creditsBalanceMoney();
        } catch (BucketNotDenominatedException $e) {
            expect($e)->toBeInstanceOf(CreditsException::class)
                ->and($e->bucket)->toBe('default')
                ->and($e->getMessage())->toContain('credits.currencies');

            return;
        }

        $this->fail('Expected BucketNotDenominatedException.');
    });
});

describe('modifyCreditsMoney', function (): void {
    it('credits and debits a denominated bucket through the integer ledger', function (): void {
        $credit = $this->user->modifyCreditsMoney(Money::ofMajor('25.00', 'EUR'), 'top-up', ['order' => 7], bucket: 'store_credit');
        $this->user->modifyCreditsMoney(Money::ofMinor(-1050, 'EUR'), 'spend', bucket: 'store_credit');

        expect($credit)->toBeInstanceOf(Credit::class)
            ->and($credit->amount)->toBe(2500)
            ->and($credit->bucket)->toBe('store_credit')
            ->and($credit->description)->toBe('top-up')
            ->and($credit->meta)->toBe(['order' => 7])
            ->and($this->user->creditsBalance(bucket: 'store_credit'))->toBe(1450)
            ->and($this->user->creditsBalanceMoney('store_credit')->toDecimal())->toBe('14.50');
    });

    it('modifies the default bucket when it is denominated and no bucket is given', function (): void {
        config()->set('credits.currencies', ['default' => 'PTS']);

        $this->user->modifyCreditsMoney(Money::ofMinor(40, 'PTS'));

        expect($this->user->creditsBalance())->toBe(40)
            ->and($this->user->creditsBalanceMoney()->equals(Money::ofMinor(40, 'PTS')))->toBeTrue();
    });

    it('keeps the overdraft guard and writes nothing on a refused debit', function (): void {
        $this->user->modifyCreditsMoney(Money::ofMinor(500, 'EUR'), bucket: 'store_credit');

        expect(fn () => $this->user->modifyCreditsMoney(Money::ofMinor(-501, 'EUR'), bucket: 'store_credit'))
            ->toThrow(InsufficientCreditsException::class);

        expect(Credit::query()->count())->toBe(1)
            ->and($this->user->creditsBalance(bucket: 'store_credit'))->toBe(500);
    });

    it('allows an overdraft when asked', function (): void {
        $this->user->modifyCreditsMoney(Money::ofMinor(-300, 'EUR'), allowOverdraft: true, bucket: 'store_credit');

        expect($this->user->creditsBalanceMoney('store_credit')->minor())->toBe('-300');
    });

    it('dispatches the same CreditsModified event as modifyCredits', function (): void {
        Event::fake([CreditsModified::class]);

        $this->user->modifyCreditsMoney(Money::ofMinor(120, 'PTS'), bucket: 'points');

        Event::assertDispatched(
            CreditsModified::class,
            fn (CreditsModified $event): bool => $event->credit->amount === 120 && $event->balance === 120,
        );
    });

    it('refuses a money of another currency and writes nothing', function (): void {
        expect(fn () => $this->user->modifyCreditsMoney(Money::ofMinor(100, 'USD'), bucket: 'store_credit'))
            ->toThrow(CurrencyMismatch::class, '[USD]');

        expect(fn () => $this->user->modifyCreditsMoney(Money::ofMinor(100, 'EUR'), bucket: 'points'))
            ->toThrow(CurrencyMismatch::class, '[PTS]');

        expect(Credit::query()->count())->toBe(0);
    });

    it('refuses a plain-credits bucket and writes nothing', function (): void {
        expect(fn () => $this->user->modifyCreditsMoney(Money::ofMinor(100, 'EUR'), bucket: 'promotional'))
            ->toThrow(BucketNotDenominatedException::class, '[promotional]');

        expect(Credit::query()->count())->toBe(0);
    });

    it('refuses an amount outside the 64-bit ledger before writing', function (string $minor): void {
        expect(fn () => $this->user->modifyCreditsMoney(Money::ofMinor($minor, 'EUR'), allowOverdraft: true, bucket: 'store_credit'))
            ->toThrow(AmountOverflow::class, "[{$minor}]");

        expect(Credit::query()->count())->toBe(0);
    })->with([
        'one past PHP_INT_MAX' => ['9223372036854775808'],
        'one below PHP_INT_MIN' => ['-9223372036854775809'],
    ]);

    it('holds an 18-decimal currency in its smallest unit, capped by the int64 ledger', function (): void {
        // 9 ETH is 9 * 10^18 wei — inside int64 (max ≈ 9.22 * 10^18).
        $this->user->modifyCreditsMoney(Money::ofMajor('9', 'ETH'), bucket: 'crypto');

        expect($this->user->creditsBalance(bucket: 'crypto'))->toBe(9_000_000_000_000_000_000)
            ->and($this->user->creditsBalanceMoney('crypto')->toDecimal())->toBe('9.000000000000000000')
            ->and($this->user->displayCreditsBalance('crypto', scale: 2))->toBe('9.00');

        // 10 ETH does not fit the bigint column: refused, nothing written.
        expect(fn () => $this->user->modifyCreditsMoney(Money::ofMajor('10', 'ETH'), bucket: 'crypto'))
            ->toThrow(AmountOverflow::class);

        expect(Credit::query()->count())->toBe(1);
    });
});

describe('displayCreditsBalance on a denominated bucket', function (): void {
    it('renders at the currency exponent and ignores credits.scale', function (): void {
        config()->set('credits.scale', 4);
        $this->user->modifyCredits(1050, bucket: 'store_credit');
        $this->user->modifyCredits(1050, bucket: 'points');

        expect($this->user->displayCreditsBalance('store_credit'))->toBe('10.50')
            ->and($this->user->displayCreditsBalance('points'))->toBe('1050')
            // An undenominated bucket still reads at credits.scale.
            ->and($this->user->displayCredits(1050))->toBe('0.1050');
    });

    it('honours a scale and rounding override from the currency exponent', function (): void {
        $this->user->modifyCredits(1050, bucket: 'store_credit');

        expect($this->user->displayCreditsBalance('store_credit', scale: 0))->toBe('11')
            ->and($this->user->displayCreditsBalance('store_credit', scale: 0, rounding: RoundingMode::HalfEven))->toBe('10')
            ->and($this->user->displayCreditsBalance('store_credit', scale: 4))->toBe('10.5000');
    });

    it('honours the point in time', function (): void {
        Carbon::setTestNow('2026-01-01 10:00:00');
        $this->user->modifyCredits(100, bucket: 'store_credit');
        Carbon::setTestNow('2026-01-02 10:00:00');
        $this->user->modifyCredits(100, bucket: 'store_credit');

        expect($this->user->displayCreditsBalance('store_credit', at: Carbon::parse('2026-01-01 12:00:00')))->toBe('1.00');
    });

    afterEach(function (): void {
        config()->set('credits.scale', 0);
    });
});

describe('formatCreditsBalance', function (): void {
    it('formats the balance through money for the given locale', function (): void {
        $this->user->modifyCredits(1050, bucket: 'store_credit');

        $formatted = $this->user->formatCreditsBalance('store_credit', 'en');

        expect($formatted)->toBe(Money::ofMinor(1050, 'EUR')->format('en'))
            ->and($formatted)->toContain('10.50');
    });

    it('formats a custom points currency', function (): void {
        $this->user->modifyCredits(1250, bucket: 'points');

        expect($this->user->formatCreditsBalance('points', 'en'))
            ->toBe(Money::ofMinor(1250, 'PTS')->format('en'))
            ->toContain('1,250');
    });

    it('refuses a plain-credits bucket', function (): void {
        $this->user->formatCreditsBalance('promotional');
    })->throws(BucketNotDenominatedException::class);
});
