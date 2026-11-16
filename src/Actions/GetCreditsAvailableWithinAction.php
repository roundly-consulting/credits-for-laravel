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
     * @param  iterable<Model&Creditable>  $alsoWithin  extra ceilings counted too, with their own parents
     *
     * @throws InvalidCreditParentException when the owner's parents cannot be walked
     * @throws AmountOverflow
     */
    public function execute(Model&Creditable $creditable, ?string $bucket = null, ?callable $availableOf = null, iterable $alsoWithin = []): CreditsAvailability
    {
        $availableOf ??= fn (Model&Creditable $owner, ?string $bucket): int => $this->available->execute($owner, $bucket);
        $levels = $this->parents->levels($creditable, $alsoWithin);
        $limit = null;

        foreach ($levels as $level) {
            $available = $availableOf($level->owner, $bucket);

            if ($limit === null || $available < $limit->available) {
                $limit = new CreditsAvailability($available, $level->owner, count($levels), $level->ceiling);
            }
        }

        /** @var CreditsAvailability $limit */
        return $limit;
    }
}
