<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;

/**
 * An owner's balance summed across several named buckets, or across every bucket it owns.
 */
final readonly class GetCreditsTotalAction
{
    /**
     * `$buckets` null sums every bucket; a list sums those (names de-duplicated, an empty
     * list is zero). `$at` limits the sum to rows recorded up to that moment.
     *
     * @param  array<int, string>|null  $buckets
     */
    public function execute(Model&Creditable $creditable, ?array $buckets = null, ?CarbonInterface $at = null): int
    {
        // Through the owner's relation: the same connection and model class as the write.
        $query = $creditable->credits()->getQuery();

        if ($buckets !== null) {
            $buckets = array_values(array_unique($buckets));

            if ($buckets === []) {
                return 0;
            }

            $query->buckets($buckets);
        }

        if ($at !== null) {
            $query->upTo($at);
        }

        return (int) $query->sum('amount');
    }
}
