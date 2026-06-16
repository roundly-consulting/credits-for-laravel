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
 * @property string $id
 * @property string $creditable_type
 * @property string $creditable_id
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
