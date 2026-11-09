<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\Credits\DataTransferObjects\CreditResetResult;
use RoundlyConsulting\Credits\Enums\ResetPeriod;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Support\CreditResets;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\LedgerConnection;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * Reset one bucket to an exact balance once per period. The period's marker row and the
 * delta row are written in one transaction under the owner lock, so a second run in the same
 * period — a double-scheduled command, two servers, a retried job — finds the marker and
 * writes nothing, and a reset the guard refuses leaves no marker (the next run retries it).
 */
final readonly class ResetCreditsAction
{
    public function __construct(
        private SetCreditsAction $set,
        private OwnerLock $lock,
    ) {}

    /**
     * @param  ResetPeriod|string  $period  A period (its key is read from now, in the app
     *                                      timezone) or an explicit period key.
     * @param  array<string, mixed>|null  $meta  The delta row's meta; `['reset_period' => key]` when null.
     *
     * @throws InvalidArgumentException when the period key is blank or longer than 64 characters
     * @throws InsufficientCreditsException when lowering the balance would breach the minimum
     */
    public function execute(
        Model&Creditable $creditable,
        ResetPeriod|string $period,
        int $to = 0,
        ?string $bucket = null,
        ?string $description = null,
        ?array $meta = null,
        bool $allowOverdraft = false,
    ): CreditResetResult {
        $key = CreditResets::key($period, CarbonImmutable::now());
        $bucket ??= CreditsConfig::defaultBucket();
        $connection = LedgerConnection::of($creditable);

        /** @var CreditResetResult $result */
        $result = $connection->transaction(function () use ($creditable, $connection, $key, $to, $bucket, $description, $meta, $allowOverdraft): CreditResetResult {
            $this->lock->acquire($creditable);

            if (! CreditResets::claim($connection, $creditable, $bucket, $key, $to)) {
                return new CreditResetResult(reset: false, period: $key);
            }

            $credit = $this->set->execute($creditable, $to, $description, $meta ?? ['reset_period' => $key], $allowOverdraft, $bucket);

            if ($credit !== null) {
                CreditResets::link($connection, $creditable, $bucket, $key, $credit->getKey());
            }

            return new CreditResetResult(reset: true, period: $key, credit: $credit);
        }, OwnerLock::ATTEMPTS);

        return $result;
    }
}
