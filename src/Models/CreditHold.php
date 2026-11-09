<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Credits\Concerns\HasConfigurableKey;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Database\Factories\CreditHoldFactory;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;

/**
 * A reservation of part of one bucket's balance. While it is held (and not past `expires_at`)
 * it lowers the owner's available balance; it writes a ledger row only when captured.
 *
 * @property int|string $id
 * @property string $creditable_type
 * @property int|string $creditable_id
 * @property string $bucket
 * @property int $amount
 * @property CreditHoldStatus $status
 * @property int|null $captured_amount
 * @property int|string|null $credit_id
 * @property string|null $description
 * @property array<string, mixed>|null $meta
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonInterface|null $deleted_at
 */
final class CreditHold extends Model
{
    use HasConfigurableKey;

    /** @use HasFactory<CreditHoldFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $table = 'credit_holds';

    protected $guarded = [];

    /** @return MorphTo<Model, $this> */
    public function creditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether the hold still reserves credits: held, and not past its expiry at `$now`.
     */
    public function isActive(?CarbonInterface $now = null): bool
    {
        return $this->status === CreditHoldStatus::Held
            && ($this->expires_at === null || $this->expires_at->greaterThan($now ?? CarbonImmutable::now()));
    }

    /**
     * Capture the hold through the manager — `Credits::capture($this, …)`.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function capture(?int $amount = null, ?string $description = null, ?array $meta = null): Credit
    {
        return app(CreditsManager::class)->capture($this, $amount, $description, $meta);
    }

    /**
     * Release the hold through the manager — `Credits::release($this)`.
     */
    public function release(): self
    {
        return app(CreditsManager::class)->release($this);
    }

    /**
     * Holds that still reserve credits at `$now`: held, with no expiry or one still ahead.
     *
     * @param  Builder<CreditHold>  $query
     * @return Builder<CreditHold>
     */
    public function scopeActive(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $now = self::storedInstant($now ?? CarbonImmutable::now());

        return $query->where('status', CreditHoldStatus::Held->value)
            ->where(static fn (Builder $active): Builder => $active->whereNull('expires_at')->orWhere('expires_at', '>', $now));
    }

    /**
     * Held holds whose expiry has passed at `$now` — what the expiry sweep finalises.
     *
     * @param  Builder<CreditHold>  $query
     * @return Builder<CreditHold>
     */
    public function scopeStale(Builder $query, ?CarbonInterface $now = null): Builder
    {
        return $query->where('status', CreditHoldStatus::Held->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', self::storedInstant($now ?? CarbonImmutable::now()));
    }

    /**
     * Limit to holds owned by a given creditable entity.
     *
     * @param  Builder<CreditHold>  $query
     * @return Builder<CreditHold>
     */
    public function scopeForCreditable(Builder $query, Model $creditable): Builder
    {
        return $query->whereMorphedTo('creditable', $creditable);
    }

    /**
     * Limit to holds in a single named bucket.
     *
     * @param  Builder<CreditHold>  $query
     * @return Builder<CreditHold>
     */
    public function scopeBucket(Builder $query, string $bucket): Builder
    {
        return $query->where('bucket', $bucket);
    }

    /**
     * An instant in `app.timezone`, the zone the timestamps are stored in: the database
     * compares wall clocks, so a Carbon in another zone must be moved before it is bound.
     */
    public static function storedInstant(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(config()->string('app.timezone'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'captured_amount' => 'integer',
            'status' => CreditHoldStatus::class,
            'meta' => 'array',
            'expires_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): CreditHoldFactory
    {
        return CreditHoldFactory::new();
    }
}
