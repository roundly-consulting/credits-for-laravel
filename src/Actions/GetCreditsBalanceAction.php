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
    public function execute(Model&Creditable $creditable, ?CarbonInterface $at = null, bool $lockForUpdate = false): int
    {
        return (int) $this->query($creditable)
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
    private function query(Model&Creditable $creditable): Builder
    {
        /** @var class-string<Credit> $model */
        $model = config('credits.model', Credit::class);

        return $model::query()->whereMorphedTo('creditable', $creditable);
    }
}
