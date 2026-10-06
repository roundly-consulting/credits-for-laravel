<?php

declare(strict_types=1);

return [
    'insufficient' => 'Insufficient credits: tried to deduct :requested but only :available are available.',
    'insufficient_above_minimum' => 'Insufficient credits: tried to deduct :requested but only :spendable can be spent above the minimum balance of :minimum.',
    'not_denominated' => 'The credits bucket [:bucket] is not denominated in a currency; map it in credits.currencies to use money amounts.',
];
