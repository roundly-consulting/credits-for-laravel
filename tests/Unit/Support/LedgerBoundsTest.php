<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\LedgerBounds;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

it('sums the ledger exactly, as an integer string', function (): void {
    expect(LedgerBounds::exactSum(Credit::query()))->toBe('0');
});

it('rethrows a query error that is not an integer overflow', function (): void {
    LedgerBounds::exactSum(Credit::query()->from('missing_ledger'));
})->throws(QueryException::class, 'missing_ledger');

it('narrows a balance to an int only when it fits', function (): void {
    expect(LedgerBounds::balance((string) PHP_INT_MIN, 'points'))->toBe(PHP_INT_MIN)
        ->and(fn (): int => LedgerBounds::balance('9223372036854775808', 'points'))
        ->toThrow(AmountOverflow::class, 'The credits balance of bucket [points] is [9223372036854775808], which does not fit a 64-bit integer.')
        ->and(LedgerBounds::balanceOverflow('points')->getMessage())
        ->toBe('The credits balance of bucket [points] does not fit a 64-bit integer.');
});
