<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Events\CreditsModified;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;

final class ModifyCreditsAction
{
    public function __construct(
        private readonly GetCreditsBalanceAction $balance,
    ) {}

    public function execute(Model&Creditable $creditable, CreditChangeData $data): Credit
    {
        /** @var Credit $credit */
        $credit = $creditable->getConnection()->transaction(function () use ($creditable, $data): Credit {
            $this->guardAgainstOverdraft($creditable, $data);

            /** @var Credit $credit */
            $credit = $creditable->credits()->create($data->toAttributes());

            return $credit;
        });

        CreditsModified::dispatch(
            $creditable,
            $credit,
            $this->balance->execute($creditable, bucket: $data->resolvedBucket()),
        );

        return $credit;
    }

    private function guardAgainstOverdraft(Model&Creditable $creditable, CreditChangeData $data): void
    {
        if ($data->amount >= 0) {
            return;
        }

        $allowOverdraft = $data->allowOverdraft || (bool) config('credits.allow_overdraft', false);

        if ($allowOverdraft) {
            return;
        }

        $available = $this->balance->execute(
            $creditable,
            lockForUpdate: true,
            bucket: $data->resolvedBucket(),
        );
        $minimum = (int) config('credits.minimum_balance', 0);

        if ($available + $data->amount < $minimum) {
            throw new InsufficientCreditsException(
                creditable: $creditable,
                requested: $data->amount,
                available: $available,
            );
        }
    }
}
