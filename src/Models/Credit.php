<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Credits\Database\Factories\CreditFactory;

/**
 * A single, immutable ledger row: the balance is the sum of an entity's rows, never a
 * stored column. Deliberately not `final` — `credits.model` documents swapping in a
 * subclass, which `final` would make impossible.
 *
 * @property string $id
 * @property string $creditable_type
 * @property string $creditable_id
 * @property string $bucket
 * @property int $amount
 * @property string|null $description
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Credit extends Model
{
    /** @use HasFactory<CreditFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $guarded = [];

    /** @return MorphTo<Model, $this> */
    public function creditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Limit to grant rows (positive amounts).
     *
     * @param  Builder<Credit>  $query
     * @return Builder<Credit>
     */
    public function scopeGrants(Builder $query): Builder
    {
        return $query->where('amount', '>', 0);
    }

    /**
     * Limit to deduction rows (negative amounts).
     *
     * @param  Builder<Credit>  $query
     * @return Builder<Credit>
     */
    public function scopeDeductions(Builder $query): Builder
    {
        return $query->where('amount', '<', 0);
    }

    /**
     * Limit to rows recorded up to (and including) a point in time.
     *
     * @param  Builder<Credit>  $query
     * @return Builder<Credit>
     */
    public function scopeUpTo(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where('created_at', '<=', $at);
    }

    /**
     * Limit to rows owned by a given creditable entity.
     *
     * @param  Builder<Credit>  $query
     * @return Builder<Credit>
     */
    public function scopeForCreditable(Builder $query, Model $creditable): Builder
    {
        return $query->whereMorphedTo('creditable', $creditable);
    }

    /**
     * Limit to rows in a single named bucket.
     *
     * @param  Builder<Credit>  $query
     * @return Builder<Credit>
     */
    public function scopeBucket(Builder $query, string $bucket): Builder
    {
        return $query->where('bucket', $bucket);
    }

    /**
     * Limit to rows in any of the given named buckets.
     *
     * @param  Builder<Credit>  $query
     * @param  array<int, string>  $buckets
     * @return Builder<Credit>
     */
    public function scopeBuckets(Builder $query, array $buckets): Builder
    {
        return $query->whereIn('bucket', $buckets);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): CreditFactory
    {
        return CreditFactory::new();
    }
}
