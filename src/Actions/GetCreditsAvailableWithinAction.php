<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\DataTransferObjects\CreditsAvailability;
use RoundlyConsulting\Credits\Exceptions\InvalidCreditParentException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Support\CreditParents;
use RoundlyConsulting\Money\Exceptions\AmountOverflow;

/**
 * What an owner can spend in one bucket within its credit parents: the smallest available
 * balance along its chain, and which owner has it. For an owner without parents (or with
 * `credits.parents.enabled` off) that is its own available balance. A read, not a promise:
 * hold the amount to keep it.
 */
final readonly class GetCreditsAvailableWithinAction
{
    public function __construct(
        private GetCreditsAvailableAction $available,
        private CreditParents $parents,
    ) {}

    /**
     * @param  (callable(Model&Creditable, string|null): int)|null  $availableOf  how one level's available balance is read; the ledger's when null
     *
     * @throws InvalidCreditParentException when the owner's parents cannot be walked
     * @throws AmountOverflow
     */
    public function execute(Model&Creditable $creditable, ?string $bucket = null, ?callable $availableOf = null): CreditsAvailability
    {
        $availableOf ??= fn (Model&Creditable $owner, ?string $bucket): int => $this->available->execute($owner, $bucket);
        $chain = $this->parents->chain($creditable);
        $limit = null;

        foreach ($chain as $owner) {
            $available = $availableOf($owner, $bucket);

            if ($limit === null || $available < $limit->available) {
                $limit = new CreditsAvailability($available, $owner, count($chain));
            }
        }

        /** @var CreditsAvailability $limit */
        return $limit;
    }
}
