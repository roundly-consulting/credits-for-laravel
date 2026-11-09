<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Enums;

use Carbon\CarbonInterface;

/**
 * The period a scheduled bucket reset runs once in. Its key names the period an instant falls
 * in, read in that instant's own timezone — `2026-11-09`, `2026-W45`, `2026-11`, `2026` — and
 * is what makes a reset idempotent: one reset per owner, bucket and key.
 */
enum ResetPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function key(CarbonInterface $at): string
    {
        return match ($this) {
            self::Daily => $at->format('Y-m-d'),
            // ISO-8601 week-numbering year, so the last days of December can belong to week 1.
            self::Weekly => $at->format('o-\WW'),
            self::Monthly => $at->format('Y-m'),
            self::Yearly => $at->format('Y'),
        };
    }
}
