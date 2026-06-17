<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Actions\FormatCreditsAction;

function format(int $amount, ?int $scale = null, ?int $rounding = null): string
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
    config()->set('credits.rounding', PHP_ROUND_HALF_UP);

    // 12.50 -> 0 decimals, exact half -> away from zero.
    expect(format(1250, scale: 0))->toBe('13')
        ->and(format(1251, scale: 0))->toBe('13')
        ->and(format(1249, scale: 0))->toBe('12');
});

it('rounds negative halves away from zero with half up', function (): void {
    config()->set('credits.scale', 2);

    expect(format(-1250, scale: 0, rounding: PHP_ROUND_HALF_UP))->toBe('-13');
});

it('rounds half down toward zero', function (): void {
    config()->set('credits.scale', 2);

    expect(format(1250, scale: 0, rounding: PHP_ROUND_HALF_DOWN))->toBe('12')
        ->and(format(-1250, scale: 0, rounding: PHP_ROUND_HALF_DOWN))->toBe('-12')
        ->and(format(1251, scale: 0, rounding: PHP_ROUND_HALF_DOWN))->toBe('13');
});

it('rounds half to even (bankers rounding)', function (): void {
    config()->set('credits.scale', 2);

    // 12.50 -> 12 (even), 13.50 -> 14 (even).
    expect(format(1250, scale: 0, rounding: PHP_ROUND_HALF_EVEN))->toBe('12')
        ->and(format(1350, scale: 0, rounding: PHP_ROUND_HALF_EVEN))->toBe('14');
});

it('rounds half to odd', function (): void {
    config()->set('credits.scale', 2);

    // 12.50 -> 13 (odd), 13.50 -> 13 (odd).
    expect(format(1250, scale: 0, rounding: PHP_ROUND_HALF_ODD))->toBe('13')
        ->and(format(1350, scale: 0, rounding: PHP_ROUND_HALF_ODD))->toBe('13');
});

it('honours a per-call rounding override that differs from the config default', function (): void {
    config()->set('credits.scale', 2);
    config()->set('credits.rounding', PHP_ROUND_HALF_DOWN);

    // Config says half-down (->12), per-call override forces half-up (->13).
    expect(format(1250, scale: 0))->toBe('12')
        ->and(format(1250, scale: 0, rounding: PHP_ROUND_HALF_UP))->toBe('13');
});

it('rounds at a non-zero smaller display scale', function (): void {
    config()->set('credits.scale', 4);

    // 1.2345 displayed at 2 places, half boundary on the dropped 45? remainder 45 of 100,
    // 90 < 100 so rounds down to 1.23.
    expect(format(12345, scale: 2, rounding: PHP_ROUND_HALF_UP))->toBe('1.23')
        ->and(format(12350, scale: 2, rounding: PHP_ROUND_HALF_UP))->toBe('1.24');
});

it('treats a negative display scale as zero', function (): void {
    config()->set('credits.scale', 2);

    expect(format(1250, scale: -1))->toBe('13');
});

afterEach(function (): void {
    config()->set('credits.scale', 0);
    config()->set('credits.rounding', PHP_ROUND_HALF_UP);
});
