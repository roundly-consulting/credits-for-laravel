<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

/**
 * A money-typed helper was called on a bucket that `credits.currencies` does not map to a
 * currency. Its integers are plain credits at `credits.scale`, so there is no honest Money
 * to hand back or accept.
 */
final class BucketNotDenominatedException extends CreditsException
{
    public function __construct(public readonly string $bucket)
    {
        parent::__construct(
            (string) trans('credits::messages.not_denominated', ['bucket' => $bucket]),
        );
    }
}
