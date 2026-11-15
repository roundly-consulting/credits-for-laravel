<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldExpired;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * The expiry sweep: mark every hold whose `expires_at` has passed as expired and dispatch
 * CreditHoldExpired for it. A stale hold stopped reserving credits the moment it expired;
 * the sweep only finalises it. Each hold is expired under its owner's lock and re-read under
 * a row lock, so a capture or release racing the sweep wins or loses cleanly — never both.
 * Returns how many holds it expired — every level of a hold on an owner with credit parents
 * counts, all expired together in one transaction; 0, without a query, while holds are off.
 */
final readonly class ExpireCreditHoldsAction
{
    public function __construct(
        private OwnerLock $lock,
    ) {}

    /**
     * @param  string|null  $connection  Where the holds live; the ledger model's connection
     *                                   (`credits.model`), else the default, when null.
     */
    public function execute(?CarbonInterface $now = null, ?string $connection = null): int
    {
        if (! CreditsConfig::holdsEnabled()) {
            return 0;
        }

        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $connection ??= (new (CreditModel::class()))->getConnectionName();
        $expired = 0;

        CreditHold::on($connection)->stale($now)->chunkById(100, function (Collection $holds) use ($now, &$expired): void {
            foreach ($holds as $hold) {
                /** @var CreditHold $hold */
                $expired += $this->expire($hold, $now);
            }
        });

        return $expired;
    }

    private function expire(CreditHold $hold, CarbonImmutable $now): int
    {
        $connection = $hold->getConnection();
        // A linked level expires with its origin, which carries the same expiry: the sweep meets
        // whichever comes first in its pages, and expires the whole group from the origin.
        $origin = $hold->isLinked()
            ? CreditHold::on($hold->getConnectionName())->whereKey($hold->originKey())->first() ?? $hold
            : $hold;
        $linked = $origin === $hold && $hold->isLinked() ? [] : CreditHolds::linked($origin);
        /** @var list<CreditHold> $expired */
        $expired = [];

        $connection->transaction(function () use ($origin, $linked, $now, $connection, &$expired): void {
            $expired = [];

            foreach ([...$linked, $origin] as $level) {
                $this->lock->acquireFor($connection, $level->creditable_type, $level->creditable_id);
            }

            foreach ([$origin, ...$linked] as $level) {
                // Still held and still past its expiry: a capture or release that took the lock
                // first has already resolved it.
                $fresh = CreditHold::on($level->getConnectionName())->whereKey($level->getKey())->stale($now)->lockForUpdate()->first();

                $fresh?->forceFill([
                    'status' => CreditHoldStatus::Expired,
                    'resolved_at' => CreditHold::storedInstant($now),
                ])->save();

                if ($fresh !== null) {
                    $expired[] = $fresh;
                }
            }
        }, OwnerLock::ATTEMPTS);

        foreach ($expired as $fresh) {
            CreditHoldExpired::dispatch($fresh);
        }

        return count($expired);
    }
}
