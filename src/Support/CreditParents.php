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
