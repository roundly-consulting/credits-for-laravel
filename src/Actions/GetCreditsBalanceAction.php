<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;

final class GetCreditsBalanceAction
{
    public function execute(
        Model&Creditable $creditable,
        ?CarbonInterface $at = null,
        bool $lockForUpdate = false,
        ?string $bucket = null,
    ): int {
        $resolvedBucket = $bucket ?? (string) config('credits.default_bucket', 'default');

        return $this->sum(
            $this->baseQuery($creditable)->bucket($resolvedBucket),
            $at,
            $lockForUpdate,
        );
    }

    /**
     * Sum the balance across several named buckets. Names are de-duplicated and an empty
     * list short-circuits to zero.
     *
     * @param  array<int, string>  $buckets
     */
    public function forBuckets(
        Model&Creditable $creditable,
        array $buckets,
        ?CarbonInterface $at = null,
    ): int {
        $buckets = array_values(array_unique($buckets));

        if ($buckets === []) {
            return 0;
        }

        return $this->sum($this->baseQuery($creditable)->buckets($buckets), $at);
    }

    /**
     * Sum the balance across every bucket the entity owns (no bucket filter).
     */
    public function forAllBuckets(Model&Creditable $creditable, ?CarbonInterface $at = null): int
    {
        return $this->sum($this->baseQuery($creditable), $at);
    }

    /**
     * @param  Builder<Credit>  $query
     */
    private function sum(Builder $query, ?CarbonInterface $at, bool $lockForUpdate = false): int
    {
        return (int) $query
            ->when(
                value: ! is_null($at),
                callback: fn (Builder $builder): Builder => $builder->where('created_at', '<=', $at),
            )
            ->when($lockForUpdate, fn (Builder $builder): Builder => $builder->lockForUpdate())
            ->sum('amount');
    }

    /**
     * @return Builder<Credit>
     */
    private function baseQuery(Model&Creditable $creditable): Builder
    {
        /** @var class-string<Credit> $model */
        $model = config('credits.model', Credit::class);

        return $model::query()->whereMorphedTo('creditable', $creditable);
    }
}
