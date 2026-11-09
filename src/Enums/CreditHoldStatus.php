<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Enums;

/**
 * Where a hold is in its life. Only `Held` reserves credits — and only until its `expires_at`
 * passes; the other three are final.
 */
enum CreditHoldStatus: string
{
    case Held = 'held';
    case Captured = 'captured';
    case Released = 'released';
    case Expired = 'expired';
}
