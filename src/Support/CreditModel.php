<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing the credit ledger from `credits.model`.
 *
 * Absent config resolves the packaged model; anything else must be that model or a subclass of
 * it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key — a
 * foreign class is never silently replaced.
 */
final class CreditModel
{
    /**
     * @return class-string<Credit>
     */
    public static function class(): string
    {
        return ModelResolver::for('credits.model', Credit::class);
    }
}
