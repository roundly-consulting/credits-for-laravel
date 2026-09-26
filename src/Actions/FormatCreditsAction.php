<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use RoundingMode;
use RoundlyConsulting\Money\Math\MinorUnits;
use RoundlyConsulting\Money\Support\RoundingModes;

final class FormatCreditsAction
{
    /**
     * Format an integer minor-unit amount into a locale-free plain decimal string.
     *
     * The stored integer is interpreted at the configured `credits.scale`. `$scale`
     * overrides how many decimal places are rendered, rounding once with `$rounding` (default
     * `credits.rounding`) when the requested scale is smaller than the stored scale.
     *
     * The rescale runs on money's arbitrary-precision integer strings, so a display scale
     * above the stored scale renders exactly — `PHP_INT_MAX` at six places is
     * `"9223372036854775807.000000"`, never a float. Scales are capped at
     * {@see MinorUnits::MAX_SCALE}. No thousands separators or locale formatting are applied.
     */
    public function execute(int $amount, ?int $scale = null, ?RoundingMode $rounding = null): string
    {
        $stored = (int) config('credits.scale', 0);
        $display = max(0, $scale ?? $stored);
        $mode = $rounding ?? RoundingModes::fromValue(config('credits.rounding'), 'credits.rounding');

        return MinorUnits::toDecimal(MinorUnits::rescale($amount, $stored, $display, $mode), $display);
    }
}
