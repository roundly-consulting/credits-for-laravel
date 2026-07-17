<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Support\Str;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Drives a model's primary key from `credits.primary_key_type`, matching the column
 * type the migration emitted for the same config value.
 *
 * This is the **inbound** key — the package's own `id`, which other packages'
 * polymorphic columns point *at*. It is distinct from an **outbound** morph key (the
 * type of the models this package points at, which the host owns). Conflating the two
 * is what shipped a `uuid` `credits.id` against a fleet of `bigint` morph columns.
 *
 * Laravel's {@see HasUniqueStringIds} already keys `getKeyType()`, `getIncrementing()`,
 * `uniqueIds()` and route-binding validation off the single `$usesUniqueIds` flag, so
 * flipping that flag in the trait initializer is the whole seam: on `bigint` the model
 * behaves exactly as if it had never used the trait.
 */
trait HasConfigurableKey
{
    use HasUniqueStringIds;

    /**
     * Toggle Laravel's unique-string-id machinery off entirely on the `bigint` default,
     * so `getKeyType()`/`getIncrementing()` fall through to the auto-incrementing parent.
     */
    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = $this->configuredKeyType() !== KeyType::BigInt;
    }

    /** Generate a key of the configured type. Never called on `bigint` — the database mints those. */
    public function newUniqueId(): string
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? (string) Str::ulid()
            : (string) Str::uuid7();
    }

    /** The configured inbound primary-key strategy for this package's own tables. */
    public function configuredKeyType(): KeyType
    {
        return KeyType::fromConfig('credits.primary_key_type');
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return $this->configuredKeyType() === KeyType::Ulid
            ? Str::isUlid($value)
            : Str::isUuid($value);
    }
}
