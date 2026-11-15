<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Interfaces\HasCreditParent;
use RoundlyConsulting\Credits\Traits\HasCredits;

/**
 * A budget inside another one — a company, a team in it, a person in that — named by
 * `parent_id`.
 *
 * @property int $id
 * @property string $name
 * @property int|null $parent_id
 */
final class Budget extends Model implements Creditable, HasCreditParent
{
    use HasCredits;

    protected $table = 'budgets';

    protected $guarded = [];

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function creditParent(): ?self
    {
        return $this->parent_id === null ? null : self::query()->find($this->parent_id);
    }

    public static function under(?self $parent, string $name, int $credits = 0): self
    {
        $budget = self::query()->create(['name' => $name, 'parent_id' => $parent?->id]);

        if ($credits !== 0) {
            // A grant stays with the budget it is for.
            $budget->modifyCredits($credits);
        }

        return $budget;
    }
}
