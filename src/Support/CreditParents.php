<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Exceptions\InvalidCreditParentException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Interfaces\HasCreditParent;

/**
 * Who caps whom: an owner's chain of credit parents, read from the resolver the app registered
 * (`Credits::resolveParentsUsing()`) or else from the owner's own `creditParent()`. Empty — the
 * owner alone — while `credits.parents.enabled` is off.
 *
 * @internal
 */
final class CreditParents
{
    /** @var (Closure(Model&Creditable): ((Model&Creditable)|null))|null */
    private ?Closure $resolver = null;

    /**
     * @param  (Closure(Model&Creditable): ((Model&Creditable)|null))|null  $resolver
     */
    public function resolveUsing(?Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * The owner first, then each ancestor up to the top.
     *
     * @return non-empty-list<Model&Creditable>
     *
     * @throws InvalidCreditParentException on a cycle, a chain deeper than the limit, or an
     *                                      ancestor whose ledger is on another connection
     */
    public function chain(Model&Creditable $owner): array
    {
        if (! CreditsConfig::parentsEnabled()) {
            return [$owner];
        }

        $max = CreditsConfig::parentsMaxDepth();
        $connection = LedgerConnection::of($owner)->getName();
        $chain = [$owner];
        $seen = [self::identity($owner) => true];
        $current = $owner;

        while (($parent = $this->parentOf($current)) !== null) {
            $identity = self::identity($parent);

            if (isset($seen[$identity])) {
                throw InvalidCreditParentException::cycle(self::identity($owner));
            }

            if (count($chain) > $max) {
                throw InvalidCreditParentException::tooDeep(self::identity($owner), $max);
            }

            if (LedgerConnection::of($parent)->getName() !== $connection) {
                throw InvalidCreditParentException::otherConnection(self::identity($current), $identity);
            }

            $seen[$identity] = true;
            $chain[] = $parent;
            $current = $parent;
        }

        return $chain;
    }

    /**
     * Every owner a hold or debit on `$owner` must fit: its chain of credit parents, then each
     * extra ceiling (`alsoWithin`) with its own parents, each owner once. Extra ceilings apply
     * whether or not `credits.parents.enabled` is on; their parents only while it is.
     *
     * @param  iterable<Model&Creditable>  $alsoWithin
     * @return non-empty-list<CreditLevel>
     *
     * @throws InvalidCreditParentException on a cycle, a chain deeper than the limit, or an owner
     *                                      whose ledger is on another connection
     */
    public function levels(Model&Creditable $owner, iterable $alsoWithin = []): array
    {
        $connection = LedgerConnection::of($owner)->getName();
        $levels = [];
        $seen = [];

        $add = static function (array $chain, bool $ceiling) use (&$levels, &$seen): void {
            $top = count($chain) - 1;

            foreach ($chain as $index => $level) {
                $identity = self::identity($level);

                if (! isset($seen[$identity])) {
                    $seen[$identity] = true;
                    $levels[] = new CreditLevel($level, $top - $index, $ceiling);
                }
            }
        };

        $add($this->chain($owner), false);

        foreach ($alsoWithin as $ceiling) {
            if (LedgerConnection::of($ceiling)->getName() !== $connection) {
                throw InvalidCreditParentException::otherConnection(self::identity($owner), self::identity($ceiling));
            }

            $add($this->chain($ceiling), true);
        }

        /** @var non-empty-list<CreditLevel> $levels */
        return $levels;
    }

    /**
     * The one order every write takes owner locks in: nearest the top first (a parent before its
     * children), then by morph type and key. Two writes sharing owners — chains, extra ceilings,
     * or both — always meet them in the same order, so they queue instead of deadlocking.
     *
     * @param  list<CreditLevel>  $levels
     * @return list<CreditLevel>
     */
    public static function lockOrder(array $levels): array
    {
        usort($levels, static fn (CreditLevel $a, CreditLevel $b): int => [$a->depth, $a->owner->getMorphClass(), self::keyOf($a->owner)]
            <=> [$b->depth, $b->owner->getMorphClass(), self::keyOf($b->owner)]);

        return $levels;
    }

    /**
     * An owner's place in the lock order — its depth from the top of its chain — for a write that
     * starts from saved holds rather than from a call.
     */
    public function depthOf(Model&Creditable $owner): int
    {
        return count($this->chain($owner)) - 1;
    }

    private static function keyOf(Model $owner): string
    {
        $key = $owner->getKey();

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * `type#key` — how an owner is named in a refusal; never more than its morph pair.
     */
    public static function identity(Model $owner): string
    {
        $key = $owner->getKey();

        return $owner->getMorphClass().'#'.(is_scalar($key) ? (string) $key : '');
    }

    private function parentOf(Model&Creditable $owner): (Model&Creditable)|null
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($owner);
        }

        return $owner instanceof HasCreditParent ? $owner->creditParent() : null;
    }
}
