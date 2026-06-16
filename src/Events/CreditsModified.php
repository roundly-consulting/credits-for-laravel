<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Models\Credit;

final class CreditsModified
{
    use Dispatchable;

    public function __construct(
        public readonly Model&Creditable $creditable,
        public readonly Credit $credit,
        public readonly int $balance,
    ) {}
}
