<?php

declare(strict_types=1);

return [
    'insufficient' => 'Insufficient credits: tried to deduct :requested but only :available are available.',
    'insufficient_above_minimum' => 'Insufficient credits: tried to deduct :requested but only :spendable can be spent above the minimum balance of :minimum.',
    'not_denominated' => 'The credits bucket [:bucket] is not denominated in a currency; map it in credits.currencies to use money amounts.',
    'hold_not_active' => 'The credit hold [:hold] can no longer be captured or released: it is :status.',
    'hold_foreign' => 'The credit hold [:hold] belongs to another owner.',
    'holds_disabled' => 'Credit holds are disabled; run the credit holds migration and set credits.holds.enabled.',
    'parent_cycle' => 'The credit parents of [:owner] form a cycle.',
    'parent_too_deep' => 'The credit parents of [:owner] go deeper than :max levels (credits.parents.max_depth).',
    'parent_connection' => 'The credit parent [:parent] of [:owner] keeps its ledger on another connection; a chain must share one.',
    'insufficient_within_parent' => 'Insufficient credits in the budget of [:owner]: tried to deduct :requested but only :available are available there.',
    'linked_hold' => 'The credit hold [:hold] is the parent level of hold [:origin]; capture or release that one.',
];
