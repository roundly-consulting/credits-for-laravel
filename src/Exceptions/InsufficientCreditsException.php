<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class InsufficientCreditsException extends CreditsException
{
    public function __construct(
        public readonly Model $creditable,
        public readonly int $requested,
        public readonly int $available,
    ) {
        parent::__construct(
            (string) trans('credits::messages.insufficient', [
                'requested' => abs($requested),
                'available' => $available,
            ]),
        );
    }
}
