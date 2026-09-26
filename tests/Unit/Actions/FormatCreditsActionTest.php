<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Actions\FormatCreditsAction;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;

function format(int $amount, ?int $scale = null, ?RoundingMode $rounding = null): string
{
    return app(FormatCreditsAction::class)->execute($amount, $scale, $rounding);
}

it('renders whole units at the default scale of zero', function (): void {
    config()->set('credits.scale', 0);

    expect(format(1235))->toBe('1235')
        ->and(format(0))->toBe('0');
});

it('renders padded decimals at the configured scale', function (): void {
    config()->set('credits.scale', 2);

    expect(format(123450))->toBe('1234.50')
        ->and(format(5))->toBe('0.05')
        ->and(format(100))->toBe('1.00');
});

it('renders negative amounts at the configured scale', function (): void {
    config()->set('credits.scale', 2);

    expect(format(-123450))->toBe('-1234.50')
        ->and(format(-5))->toBe('-0.05');
});

it('renders negative whole units at scale zero', function (): void {
    config()->set('credits.scale', 0);

    expect(format(-42))->toBe('-42');
});

it('scales up exactly when the display scale exceeds the stored scale', function (): void {
    config()->set('credits.scale', 2);

    expect(format(150, scale: 4))->toBe('1.5000');
});

it('rounds half up by default when the display scale is smaller than the stored scale', function (): void {
    config()->set('credits.scale', 2);
    config()->set('credits.rounding', 'half_away_from_zero');

    // 12.50 -> 0 decimals, exact half -> away from zero.
    expect(format(1250, scale: 0))->toBe('13')
        ->and(format(1251, scale: 0))->toBe('13')
        ->and(format(1249, scale: 0))->toBe('12');
});

it('rounds negative halves away from zero with half up', function (): void {
    config()->set('credits.scale', 2);

    expect(format(-1250, scale: 0, rounding: RoundingMode::HalfAwayFromZero))->toBe('-13');
});

it('rounds half down toward zero', function (): void {
    config()->set('credits.scale', 2);

    expect(format(1250, scale: 0, rounding: RoundingMode::HalfTowardsZero))->toBe('12')
        ->and(format(-1250, scale: 0, rounding: RoundingMode::HalfTowardsZero))->toBe('-12')
        ->and(format(1251, scale: 0, rounding: RoundingMode::HalfTowardsZero))->toBe('13');
});

it('rounds half to even (bankers rounding)', function (): void {
    config()->set('credits.scale', 2);

    // 12.50 -> 12 (even), 13.50 -> 14 (even).
    expect(format(1250, scale: 0, rounding: RoundingMode::HalfEven))->toBe('12')
        ->and(format(1350, scale: 0, rounding: RoundingMode::HalfEven))->toBe('14');
});

it('rounds half to odd', function (): void {
    config()->set('credits.scale', 2);

    // 12.50 -> 13 (odd), 13.50 -> 13 (odd).
    expect(format(1250, scale: 0, rounding: RoundingMode::HalfOdd))->toBe('13')
        ->and(format(1350, scale: 0, rounding: RoundingMode::HalfOdd))->toBe('13');
});

it('honours a per-call rounding override that differs from the config default', function (): void {
    config()->set('credits.scale', 2);
    config()->set('credits.rounding', 'half_towards_zero');

    // Config says half-down (->12), per-call override forces half-up (->13).
    expect(format(1250, scale: 0))->toBe('12')
        ->and(format(1250, scale: 0, rounding: RoundingMode::HalfAwayFromZero))->toBe('13');
});

it('rounds at a non-zero smaller display scale', function (): void {
    config()->set('credits.scale', 4);

    // 1.2345 displayed at 2 places, half boundary on the dropped 45? remainder 45 of 100,
    // 90 < 100 so rounds down to 1.23.
    expect(format(12345, scale: 2, rounding: RoundingMode::HalfAwayFromZero))->toBe('1.23')
        ->and(format(12350, scale: 2, rounding: RoundingMode::HalfAwayFromZero))->toBe('1.24');
});

it('treats a negative display scale as zero', function (): void {
    config()->set('credits.scale', 2);

    expect(format(1250, scale: -1))->toBe('13');
});

it('scales up past the int64 range exactly instead of degrading to a float', function (): void {
    config()->set('credits.scale', 0);

    // The old integer rescale computed PHP_INT_MAX * 10 ** 6, which silently became a float.
    expect(format(PHP_INT_MAX, scale: 6))->toBe('9223372036854775807.000000')
        ->and(format(PHP_INT_MIN, scale: 3))->toBe('-9223372036854775808.000');
});

it('rounds the extreme int64 values without overflowing', function (): void {
    config()->set('credits.scale', 2);

    expect(format(PHP_INT_MAX, scale: 0))->toBe('92233720368547758')
        ->and(format(PHP_INT_MIN, scale: 0))->toBe('-92233720368547758');
});

it('reads the configured rounding mode by its snake_case name', function (string $configured, string $expected): void {
    config()->set('credits.scale', 2);
    config()->set('credits.rounding', $configured);

    expect(format(-1250, scale: 0))->toBe($expected);
})->with([
    'half_away_from_zero (was PHP_ROUND_HALF_UP)' => ['half_away_from_zero', '-13'],
    'half_towards_zero (was PHP_ROUND_HALF_DOWN)' => ['half_towards_zero', '-12'],
    'half_even (was PHP_ROUND_HALF_EVEN)' => ['half_even', '-12'],
    'half_odd (was PHP_ROUND_HALF_ODD)' => ['half_odd', '-13'],
    'towards_zero' => ['towards_zero', '-12'],
    'negative_infinity' => ['negative_infinity', '-13'],
]);

it('accepts a native rounding mode set directly in config', function (): void {
    config()->set('credits.scale', 2);
    config()->set('credits.rounding', RoundingMode::HalfEven);

    expect(format(1350, scale: 0))->toBe('14');
});

it('refuses an unknown or legacy rounding config value at first use', function (mixed $configured): void {
    config()->set('credits.scale', 2);
    config()->set('credits.rounding', $configured);

    format(1250, scale: 0);
})->with([
    'ambiguous alias' => ['half_up'],
    'legacy PHP_ROUND_* constant' => [PHP_ROUND_HALF_UP],
    'null' => [null],
])->throws(InvalidMoneyConfiguration::class, '[credits.rounding]');

it('does not consult the rounding config when a mode is passed per call', function (): void {
    config()->set('credits.scale', 2);
    config()->set('credits.rounding', 'nonsense');

    expect(format(1250, scale: 0, rounding: RoundingMode::HalfEven))->toBe('12');
});

it('refuses a display scale beyond the supported maximum', function (): void {
    format(1, scale: 37);
})->throws(InvalidAmount::class);

afterEach(function (): void {
    config()->set('credits.scale', 0);
    config()->set('credits.rounding', 'half_away_from_zero');
});
