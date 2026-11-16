<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\Credits\DataTransferObjects\CreditHoldData;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Exceptions\CreditHoldsDisabledException;
use RoundlyConsulting\Credits\Exceptions\LinkedCreditHoldException;
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
     * The amount a capture takes: the whole hold, or a part of it — or, with an overdraft
     * allowed, more than was held.
     *
     * @throws InvalidArgumentException
     */
    public static function captureAmount(CreditHold $hold, ?int $amount, bool $allowOverdraft = false): int
    {
        $amount ??= $hold->amount;

        if ($amount < 1 || ($amount > $hold->amount && ! $allowOverdraft)) {
            throw new InvalidArgumentException("A capture takes 1 to {$hold->amount} of hold [{$hold->getKey()}], {$amount} given; release() frees a hold without a capture, and allowOverdraft captures more than was held.");
        }

        return $amount;
    }

    /**
     * Take the owner locks of a hold's levels in the one global order
     * ({@see CreditParents::lockOrder()}), by morph pair: an owner that is gone is still locked.
     *
     * @param  list<CreditHold>  $holds
     */
    public static function lockLevels(OwnerLock $lock, CreditParents $parents, ConnectionInterface $connection, array $holds): void
    {
        $order = [];

        foreach ($holds as $hold) {
            $owner = $hold->relationLoaded('creditable') ? $hold->getRelation('creditable') : $hold->creditable()->getResults();
            $depth = $owner instanceof Model && $owner instanceof Creditable ? $parents->depthOf($owner) : 0;
            $order[] = [$depth, $hold->creditable_type, (string) $hold->creditable_id];
        }

        usort($order, static fn (array $a, array $b): int => $a <=> $b);

        foreach ($order as [, $type, $id]) {
            $lock->acquireFor($connection, $type, $id);
        }
    }

    /**
     * Forget which connections have the linking columns (tests that rebuild the schema).
     *
     * @internal
     */
    public static function forgetSchema(): void
    {
        self::$linkable = [];
    }

    /**
     * @throws LinkedCreditHoldException when the hold is an ancestor's level of another one
     */
    public static function ensureOrigin(CreditHold $hold): void
    {
        if ($hold->isLinked()) {
            throw new LinkedCreditHoldException($hold);
        }
    }

    /** @var array<string, bool> whether each connection's holds table has the linking columns */
    private static array $linkable = [];

    /**
     * The other levels of a hold — its owner's parents and any extra ceilings — top level first.
     * None where the linking migration has not run (a host without parents or ceilings).
     *
     * @return list<CreditHold>
     */
    public static function linked(CreditHold $origin): array
    {
        $connection = $origin->getConnection();

        if (! (self::$linkable[(string) $connection->getName()] ??= $connection->getSchemaBuilder()->hasColumn($origin->getTable(), 'origin_hold_id'))) {
            return [];
        }

        // Owners eager-loaded (one query per morph type): the levels come back as a collection,
        // so reading each one's owner lazily would trip Model::preventLazyLoading().
        return array_values(CreditHold::on($origin->getConnectionName())
            ->with('creditable')
            ->where('origin_hold_id', $origin->getKey())
            ->orderByDesc('chain_depth')
            ->get()
            ->all());
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
     * The hold's owner, which a capture debits: the loaded relation, else one query. Never a
     * lazy load, so a hold the host took from a collection works under
     * Model::preventLazyLoading().
     *
     * @throws InvalidArgumentException when it is gone or not Creditable
     */
    public static function owner(CreditHold $hold): Model&Creditable
    {
        $owner = $hold->relationLoaded('creditable') ? $hold->getRelation('creditable') : $hold->creditable()->getResults();

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
