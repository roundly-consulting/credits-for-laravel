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
final class Credit extends Model
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
     * Sum the credit amounts for a creditable entity (optionally at a point in time).
     */
    public function balance(?Model $creditable = null, ?CarbonInterface $at = null): int
    {
        return (int) $this->newQuery()
            ->when(
                value: ! is_null($creditable),
                callback: fn (Builder $builder): Builder => $builder->whereMorphedTo('creditable', $creditable),
            )
            ->when(
                value: is_null($creditable) && $this->creditable_type && $this->creditable_id,
                callback: fn (Builder $builder): Builder => $builder->where('creditable_type', $this->creditable_type)
                    ->where('creditable_id', $this->creditable_id),
            )
            ->when(
                value: ! is_null($at),
                callback: fn (Builder $builder): Builder => $builder->where('created_at', '<=', $at),
            )
            ->sum('amount');
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
