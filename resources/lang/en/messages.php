<?php

declare(strict_types=1);

return [
    'insufficient' => 'Insufficient credits: tried to deduct :requested but only :available are available.',
    'insufficient_above_minimum' => 'Insufficient credits: tried to deduct :requested but only :spendable can be spent above the minimum balance of :minimum.',
    'not_denominated' => 'The credits bucket [:bucket] is not denominated in a currency; map it in credits.currencies to use money amounts.',
    'hold_not_active' => 'The credit hold [:hold] can no longer be captured or released: it is :status.',
    'hold_foreign' => 'The credit hold [:hold] belongs to another owner.',
    'holds_disabled' => 'Credit holds are disabled; run the credit holds migration and set credits.holds.enabled.',
];
