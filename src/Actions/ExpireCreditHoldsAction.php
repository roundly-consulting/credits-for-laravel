<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldExpired;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * The expiry sweep: mark every hold whose `expires_at` has passed as expired and dispatch
 * CreditHoldExpired for it. A stale hold stopped reserving credits the moment it expired;
 * the sweep only finalises it. Each hold is expired under its owner's lock and re-read under
 * a row lock, so a capture or release racing the sweep wins or loses cleanly — never both.
 * Returns how many holds it expired; 0, without a query, while holds are off.
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
                if ($this->expire($hold, $now)) {
                    $expired++;
                }
            }
        });

        return $expired;
    }

    private function expire(CreditHold $hold, CarbonImmutable $now): bool
    {
        $connection = $hold->getConnection();

        /** @var CreditHold|null $fresh */
        $fresh = $connection->transaction(function () use ($hold, $now, $connection): ?CreditHold {
            $this->lock->acquireFor($connection, $hold->creditable_type, $hold->creditable_id);

            // Still held and still past its expiry: a capture or release that took the lock
            // first has already resolved it.
            $fresh = CreditHold::on($hold->getConnectionName())->whereKey($hold->getKey())->stale($now)->lockForUpdate()->first();

            $fresh?->forceFill([
                'status' => CreditHoldStatus::Expired,
                'resolved_at' => CreditHold::storedInstant($now),
            ])->save();

            return $fresh;
        }, OwnerLock::ATTEMPTS);

        if ($fresh === null) {
            return false;
        }

        CreditHoldExpired::dispatch($fresh);

        return true;
    }
}
