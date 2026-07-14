<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;

/**
 * @template TModel of LockRecordingCredit
 *
 * @extends Builder<TModel>
 */
final class LockRecordingBuilder extends Builder
{
    public function lockForUpdate(): static
    {
        LockRecordingCredit::$locks[] = $this->getConnection()->transactionLevel();

        $this->getQuery()->lockForUpdate();

        return $this;
    }
}
