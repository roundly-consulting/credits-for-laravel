<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Handles;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Interfaces\Creditable;

/**
 * A read-only view of several of one owner's buckets — `Credits::for($user)->buckets([...])`.
 * There is no write here: a change always names exactly one bucket.
 */
final readonly class CreditBuckets
{
    /**
     * @param  array<int, string>  $buckets
     */
    public function __construct(
        private CreditsManager $manager,
        private Model&Creditable $owner,
        private array $buckets,
    ) {}

    /**
     * The balance summed across these buckets (names de-duplicated; none is zero).
     */
    public function balance(?CarbonInterface $at = null): int
    {
        return $this->manager->total($this->owner, $this->buckets, $at);
    }

    public function has(int $amount = 1, ?CarbonInterface $at = null): bool
    {
        return $this->balance($at) >= $amount;
    }
}
