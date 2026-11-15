<?php

declare(strict_types=1);

return [
    'insufficient' => 'Nedostatočný zostatok kreditov: pokus o odpočítanie :requested, k dispozícii je však iba :available.',
    'insufficient_above_minimum' => 'Nedostatočný zostatok kreditov: pokus o odpočítanie :requested, nad minimálnym zostatkom :minimum je však možné minúť iba :spendable.',
    'not_denominated' => 'Zásobník kreditov [:bucket] nemá určenú menu; ak chcete používať peňažné sumy, priraďte mu menu v credits.currencies.',
    'hold_not_active' => 'Rezerváciu kreditov [:hold] už nie je možné zúčtovať ani uvoľniť: jej stav je :status.',
    'hold_foreign' => 'Rezervácia kreditov [:hold] patrí inému vlastníkovi.',
    'holds_disabled' => 'Rezervácie kreditov sú vypnuté; spustite migráciu rezervácií kreditov a nastavte credits.holds.enabled.',
    'parent_cycle' => 'Nadradení vlastníci kreditov [:owner] tvoria cyklus.',
    'parent_too_deep' => 'Nadradení vlastníci kreditov [:owner] siahajú hlbšie ako :max úrovní (credits.parents.max_depth).',
    'parent_connection' => 'Nadradený vlastník kreditov [:parent] vlastníka [:owner] má účtovnú knihu na inom pripojení; celý reťazec musí zdieľať jedno.',
    'insufficient_within_parent' => 'Nedostatočný zostatok kreditov v rozpočte [:owner]: pokus o odpočítanie :requested, k dispozícii je tam však iba :available.',
    'linked_hold' => 'Rezervácia kreditov [:hold] je nadradenou úrovňou rezervácie [:origin]; zúčtujte alebo uvoľnite tú.',
];
