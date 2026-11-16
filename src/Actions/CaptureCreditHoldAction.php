<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;
use RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Events\CreditHoldCaptured;
use RoundlyConsulting\Credits\Exceptions\CreditHoldNotActiveException;
use RoundlyConsulting\Credits\Exceptions\LinkedCreditHoldException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditHolds;
use RoundlyConsulting\Credits\Support\CreditParents;
use RoundlyConsulting\Credits\Support\LedgerConnection;
use RoundlyConsulting\Credits\Support\OwnerLock;

/**
 * Turn a hold into a ledger debit — of the whole hold, or of part of it with the rest
 * released, or (with an overdraft allowed) of more than was held. Under the owner lock the
 * hold is re-read under a row lock and must still be active, so a hold is captured at most
 * once, and never after it expired or was released; the hold's update and the debit commit
 * together.
 *
 * The debit is not guarded against the minimum balance: the hold already reserved it — and a
 * capture beyond the hold is one the caller allowed to overdraw.
 *
 * A hold made on an owner with credit parents is captured as a whole: every level's owner is
 * locked from the top down, and every level is captured and debited by the same amount, with
 * CreditHoldCaptured and CreditsModified for each. Dispatches CreditHoldCaptured (and
 * CreditsModified for the debit).
 */
final readonly class CaptureCreditHoldAction
{
    public function __construct(
        private ModifyCreditsAction $modify,
        private OwnerLock $lock,
        private CreditParents $parents,
    ) {}

    /**
     * @param  array<string, mixed>|null  $meta  The debit's meta; the hold's when null.
     *
     * @throws CreditHoldNotActiveException
     * @throws LinkedCreditHoldException when the hold is an ancestor's level of another one
     * @throws InvalidArgumentException when the amount is outside 1..the held amount (without an
     *                                  overdraft), or an owner is gone
     */
    public function execute(CreditHold $hold, ?int $amount = null, ?string $description = null, ?array $meta = null, bool $allowOverdraft = false): Credit
    {
        CreditHolds::ensureOrigin($hold);

        $owner = CreditHolds::owner($hold);
        $captured = CreditHolds::captureAmount($hold, $amount, $allowOverdraft);

        /** @var list<array{Model&Creditable, CreditHold, Credit}> $levels */
        $levels = [];

        LedgerConnection::of($owner)->transaction(function () use ($hold, $owner, $captured, $description, $meta, &$levels): void {
            $levels = [];
            $linked = CreditHolds::linked($hold);
            $owners = array_map(CreditHolds::owner(...), $linked);

            CreditHolds::lockLevels($this->lock, $this->parents, LedgerConnection::of($owner), [...$linked, $hold]);

            $now = CarbonImmutable::now();
            $fresh = CreditHolds::reread($hold);

            CreditHolds::ensureActive($fresh, $now);

            $levels[] = $this->captureLevel($owner, $fresh, $captured, $description, $meta, $now);

            foreach ($linked as $i => $level) {
                $level = CreditHolds::reread($level);

                // A level resolved on its own (a sweep that reached it first) reserves nothing any more.
                if ($level->status === CreditHoldStatus::Held) {
                    $levels[] = $this->captureLevel($owners[$i], $level, $captured, $description, $meta, $now);
                }
            }
        }, OwnerLock::ATTEMPTS);

        $origin = null;

        foreach ($levels as [$levelOwner, $levelHold, $credit]) {
            if ($origin === null) {
                // The caller's own instance carries the outcome.
                $hold->setRawAttributes($levelHold->getAttributes(), true);
                $levelHold = $hold;
                $origin = $credit;
            }

            CreditHoldCaptured::dispatch($levelOwner, $levelHold, $credit, max(0, $levelHold->amount - ($levelHold->captured_amount ?? 0)));
        }

        return $origin ?? throw new LogicException('A capture always captures the hold it was given.');
    }

    /**
     * Debit one level — this owner only — and mark its hold captured.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array{Model&Creditable, CreditHold, Credit}
     */
    private function captureLevel(Model&Creditable $owner, CreditHold $hold, int $captured, ?string $description, ?array $meta, CarbonImmutable $now): array
    {
        $credit = $this->modify->execute($owner, new CreditChangeData(
            amount: -$captured,
            description: $description ?? $hold->description,
            meta: $meta ?? $hold->meta,
            allowOverdraft: true,
            bucket: $hold->bucket,
        ), withinParents: false);

        $hold->forceFill([
            'status' => CreditHoldStatus::Captured,
            'captured_amount' => $captured,
            'credit_id' => $credit->getKey(),
            'resolved_at' => CreditHold::storedInstant($now),
        ])->save();

        return [$owner, $hold, $credit];
    }
}
