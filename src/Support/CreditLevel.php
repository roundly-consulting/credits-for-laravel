<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;

/**
 * One owner a hold or debit must fit: the owner the call was made for, one of its credit
 * parents, or an extra ceiling (`alsoWithin()`) or one of its parents. `$depth` is the owner's
 * distance from the top of its own chain (0 at the top) — the key of the one global lock order.
 *
 * @internal
 */
final readonly class CreditLevel
{
    public function __construct(
        public Model&Creditable $owner,
        public int $depth,
        public bool $ceiling = false,
    ) {}
}
