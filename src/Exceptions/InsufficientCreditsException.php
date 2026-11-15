<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Support\CreditParents;
use RoundlyConsulting\Credits\Support\Int64;

/**
 * A debit would take the bucket below `credits.minimum_balance`. `$available` is the raw
 * balance and `$minimum` the floor the guard enforced, so `$available - $minimum` is what
 * could be spent; the message shows that amount whenever the floor is not 0.
 *
 * `$creditable` is the owner that blocked. On an owner with credit parents that can be an
 * ancestor: `$requestedBy` is then the owner the call was made on, and the message names the
 * blocking owner (`ownerType()`, `ownerKey()`).
 */
final class InsufficientCreditsException extends CreditsException
{
    public function __construct(
        public readonly Model $creditable,
        public readonly int $requested,
        public readonly int $available,
        public readonly int $minimum = 0,
        public readonly ?Model $requestedBy = null,
    ) {
        parent::__construct(match (true) {
            $this->blockedByParent() => (string) trans('credits::messages.insufficient_within_parent', [
                'owner' => CreditParents::identity($creditable),
                'requested' => abs($requested),
                'available' => Int64::subtract($available, $minimum),
            ]),
            $minimum === 0 => (string) trans('credits::messages.insufficient', [
                'requested' => abs($requested),
                'available' => $available,
            ]),
            default => (string) trans('credits::messages.insufficient_above_minimum', [
                'requested' => abs($requested),
                'spendable' => Int64::subtract($available, $minimum),
                'minimum' => $minimum,
            ]),
        });
    }

    /** Whether a credit parent of the owner the call was made on blocked it, not that owner itself. */
    public function blockedByParent(): bool
    {
        return $this->requestedBy !== null && ! $this->requestedBy->is($this->creditable);
    }

    /** The blocking owner's morph type. */
    public function ownerType(): string
    {
        return $this->creditable->getMorphClass();
    }

    /** The blocking owner's key. */
    public function ownerKey(): mixed
    {
        return $this->creditable->getKey();
    }
}
