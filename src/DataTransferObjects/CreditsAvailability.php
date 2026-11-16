<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;

/**
 * What an owner can spend in one bucket once its credit parents are counted: the smallest
 * available balance along its chain, and the owner that has it.
 */
final readonly class CreditsAvailability
{
    /**
     * @param  int  $available  The chain's minimum available balance (balance minus active holds).
     * @param  Model&Creditable  $limitedBy  The owner with that minimum — the nearest one on a tie.
     * @param  int  $levels  How many owners the chain has, the owner and extra ceilings included.
     * @param  bool  $limitedByCeiling  Whether an extra ceiling (`alsoWithin`), or one of its parents, sets the limit.
     */
    public function __construct(
        public int $available,
        public Model&Creditable $limitedBy,
        public int $levels = 1,
        public bool $limitedByCeiling = false,
    ) {}

    /** Whether an ancestor, not the owner itself or an extra ceiling, sets the limit. */
    public function limitedByParent(Model $owner): bool
    {
        return ! $this->limitedByCeiling && ! $this->limitedBy->is($owner);
    }
}
