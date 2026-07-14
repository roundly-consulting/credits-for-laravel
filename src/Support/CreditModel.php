<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Resolves the Eloquent model backing the credit ledger from `credits.model`.
 *
 * The toolkit's ModelResolver validates that the configured value is a real
 * Eloquent model; anything that isn't a Credit (so it could not answer the
 * ledger's scopes or carry the bucket/amount columns) falls back to the
 * packaged model.
 */
final class CreditModel
{
    /**
     * @return class-string<Credit>
     */
    public static function class(): string
    {
        $model = ModelResolver::for('credits.model', Credit::class);

        return is_a($model, Credit::class, true) ? $model : Credit::class;
    }
}
