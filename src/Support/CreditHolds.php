<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\Credits\DataTransferObjects\CreditHoldData;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Exceptions\CreditHoldsDisabledException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\CreditHold;

/**
 * The rules the hold actions and CreditsFake share: where an owner's holds live (the
 * ledger's connection), what a hold may be asked for, and when one can still be resolved.
 *
 * @internal
 */
final class CreditHolds
{
    /**
     * The owner's holds, on the ledger's connection ({@see LedgerConnection}).
     *
     * @return Builder<CreditHold>
     */
    public static function of(Model&Creditable $creditable): Builder
    {
        return CreditHold::on(LedgerConnection::of($creditable)->getName())->forCreditable($creditable);
    }

    /**
     * @throws CreditHoldsDisabledException
     */
    public static function ensureEnabled(): void
    {
        if (! CreditsConfig::holdsEnabled()) {
            throw new CreditHoldsDisabledException;
        }
    }

    /**
     * A hold reserves a positive amount, until a future instant (or the configured lifetime,
     * or forever). Returns that instant in the zone the timestamps are stored in.
     *
     * @throws InvalidArgumentException
     */
    public static function expiry(CreditHoldData $data, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($data->amount <= 0) {
            throw new InvalidArgumentException("A hold takes a positive amount, {$data->amount} given.");
        }

        $minutes = CreditsConfig::holdExpireAfterMinutes();
        $expiresAt = $data->expiresAt ?? ($minutes === null ? null : $now->addMinutes($minutes));

        if ($expiresAt !== null && ! $expiresAt->greaterThan($now)) {
            throw new InvalidArgumentException('A hold must expire in the future, '.$expiresAt->toIso8601String().' given.');
        }

        return $expiresAt === null ? null : CreditHold::storedInstant($expiresAt);
    }

    /**
     * The amount a capture takes: the whole hold, or a part of it.
     *
     * @throws InvalidArgumentException
     */
    public static function captureAmount(CreditHold $hold, ?int $amount): int
    {
        $amount ??= $hold->amount;

        if ($amount < 1 || $amount > $hold->amount) {
            throw new InvalidArgumentException("A capture takes 1 to {$hold->amount} of hold [{$hold->getKey()}], {$amount} given; release() frees a hold without a capture.");
        }

        return $amount;
    }

    /**
     * @throws CreditHoldNotActiveException unless the hold still reserves credits at `$now`
     */
    public static function ensureActive(CreditHold $hold, CarbonInterface $now): void
    {
        if (! $hold->isActive($now)) {
            throw new CreditHoldNotActiveException(
                $hold,
                $hold->status === CreditHoldStatus::Held ? CreditHoldStatus::Expired->value : $hold->status->value,
            );
        }
    }

    /**
     * Whether the hold belongs to the owner — a scope's boundary.
     */
    public static function ownedBy(CreditHold $hold, Model&Creditable $creditable): bool
    {
        return $hold->creditable_type === $creditable->getMorphClass()
            && (string) $hold->creditable_id === (string) $creditable->getKey();
    }

    /**
     * The hold's owner, which a capture debits.
     *
     * @throws InvalidArgumentException when it is gone or not Creditable
     */
    public static function owner(CreditHold $hold): Model&Creditable
    {
        $owner = $hold->creditable;

        if (! $owner instanceof Model || ! $owner instanceof Creditable) {
            throw new InvalidArgumentException("The owner of credit hold [{$hold->getKey()}] is gone or not Creditable.");
        }

        return $owner;
    }

    /**
     * The hold as committed, under a row lock. A deleted hold reads as no longer active.
     *
     * @throws CreditHoldNotActiveException
     */
    public static function reread(CreditHold $hold): CreditHold
    {
        return CreditHold::on($hold->getConnectionName())->whereKey($hold->getKey())->lockForUpdate()->first()
            ?? throw new CreditHoldNotActiveException($hold, 'deleted');
    }
}
