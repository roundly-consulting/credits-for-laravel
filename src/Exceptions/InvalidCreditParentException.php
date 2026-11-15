<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

/**
 * An owner's chain of credit parents cannot be walked: it loops back on itself, goes deeper than
 * `credits.parents.max_depth`, or reaches an owner whose ledger lives on another connection —
 * where one transaction could not cover the whole chain.
 */
final class InvalidCreditParentException extends CreditsException
{
    public static function cycle(string $owner): self
    {
        return new self((string) trans('credits::messages.parent_cycle', ['owner' => $owner]));
    }

    public static function tooDeep(string $owner, int $max): self
    {
        return new self((string) trans('credits::messages.parent_too_deep', ['owner' => $owner, 'max' => $max]));
    }

    public static function otherConnection(string $owner, string $parent): self
    {
        return new self((string) trans('credits::messages.parent_connection', ['owner' => $owner, 'parent' => $parent]));
    }
}
