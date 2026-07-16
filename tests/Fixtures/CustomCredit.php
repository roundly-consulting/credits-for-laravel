<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * The host subclass `credits.model` invites, used to prove the seam is real.
 *
 * `CountsCreations` is what makes the proof independent: it counts rows created as *this
 * exact class*, so a ledger row created as the packaged Credit — which would still pass an
 * `instanceof` check while firing none of the host's model events (permissions #31) —
 * cannot be mistaken for an honoured swap.
 */
class CustomCredit extends Credit
{
    use CountsCreations;

    protected $table = 'credits';
}
