<?php

declare(strict_types=1);

use RoundlyConsulting\Credits\Support\Int64;

it('adds and subtracts exactly past the int64 range', function (): void {
    expect(Int64::add(PHP_INT_MAX, 1))->toBe('9223372036854775808')
        ->and(Int64::subtract(PHP_INT_MIN, 1))->toBe('-9223372036854775809')
        ->and(Int64::add(40, -50))->toBe('-10');
});

it('narrows an integer string to an int only when it fits', function (string $integer, ?int $int): void {
    expect(Int64::toInt($integer))->toBe($int);
})->with([
    'the top' => ['9223372036854775807', PHP_INT_MAX],
    'one past the top' => ['9223372036854775808', null],
    'the bottom' => ['-9223372036854775808', PHP_INT_MIN],
    'one past the bottom' => ['-9223372036854775809', null],
    'a decimal sum' => ['145.00', 145],
]);

it('refuses a string that is not a number', function (): void {
    Int64::toInt('abc');
})->throws(InvalidArgumentException::class, '[abc]');
