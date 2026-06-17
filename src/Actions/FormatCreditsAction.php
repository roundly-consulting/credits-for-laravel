<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

final class FormatCreditsAction
{
    /**
     * Format an integer minor-unit amount into a locale-free plain decimal string.
     *
     * The stored integer is interpreted at the configured `credits.scale` (the number of
     * decimal places it encodes). `$scale` overrides how many decimal places are rendered,
     * rounding to that precision with `$rounding` (one of the PHP_ROUND_HALF_* constants)
     * when the requested scale is smaller than the stored scale. No thousands separators or
     * locale formatting are applied — wrap the result with Illuminate\Support\Number if you
     * need that.
     */
    public function execute(int $amount, ?int $scale = null, ?int $rounding = null): string
    {
        $storedScale = (int) config('credits.scale', 0);
        $displayScale = $scale ?? $storedScale;
        $mode = $rounding ?? (int) config('credits.rounding', PHP_ROUND_HALF_UP);

        if ($displayScale < 0) {
            $displayScale = 0;
        }

        // Reduce the stored minor units to the requested display precision, honouring the
        // rounding mode exactly for half-boundary cases. All math stays in integers to
        // avoid floating-point drift for realistic balances.
        $minorUnits = $this->rescale($amount, $storedScale, $displayScale, $mode);

        return $this->toDecimalString($minorUnits, $displayScale);
    }

    /**
     * Convert minor units encoded at $fromScale into minor units encoded at $toScale,
     * rounding the dropped digits with the given PHP_ROUND_HALF_* mode when $toScale is
     * smaller. When $toScale is greater or equal, the value is scaled up exactly.
     */
    private function rescale(int $amount, int $fromScale, int $toScale, int $mode): int
    {
        if ($toScale >= $fromScale) {
            return $amount * (10 ** ($toScale - $fromScale));
        }

        $divisor = 10 ** ($fromScale - $toScale);
        $negative = $amount < 0;
        $magnitude = $negative ? -$amount : $amount;

        $quotient = intdiv($magnitude, $divisor);
        $remainder = $magnitude % $divisor;

        if ($remainder !== 0) {
            $quotient += $this->roundingIncrement($remainder, $divisor, $quotient, $mode);
        }

        return $negative ? -$quotient : $quotient;
    }

    /**
     * Decide whether the truncated quotient should be bumped up by one, based on the dropped
     * remainder and the chosen rounding mode. All inputs are non-negative magnitudes; the
     * caller re-applies the sign.
     */
    private function roundingIncrement(int $remainder, int $divisor, int $quotient, int $mode): int
    {
        $twiceRemainder = $remainder * 2;

        if ($twiceRemainder < $divisor) {
            return 0;
        }

        if ($twiceRemainder > $divisor) {
            return 1;
        }

        // Exactly on the half boundary: the mode decides.
        return match ($mode) {
            PHP_ROUND_HALF_DOWN => 0,
            PHP_ROUND_HALF_EVEN => $quotient % 2 === 0 ? 0 : 1,
            PHP_ROUND_HALF_ODD => $quotient % 2 === 0 ? 1 : 0,
            default => 1, // PHP_ROUND_HALF_UP
        };
    }

    private function toDecimalString(int $minorUnits, int $scale): string
    {
        if ($scale === 0) {
            return (string) $minorUnits;
        }

        $negative = $minorUnits < 0;
        $digits = (string) ($negative ? -$minorUnits : $minorUnits);
        $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);

        $integerPart = substr($digits, 0, -$scale);
        $fractionPart = substr($digits, -$scale);

        return ($negative ? '-' : '').$integerPart.'.'.$fractionPart;
    }
}
