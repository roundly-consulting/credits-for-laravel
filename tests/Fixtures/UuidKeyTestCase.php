<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use RoundlyConsulting\Credits\Tests\TestCase;

/**
 * The suite's base case with `credits.primary_key_type` set to `uuid` BEFORE the providers
 * boot and before the migration runs — the only window that matters, since the migration
 * reads the key type to pick the `id` column's type and the model reads it to decide
 * whether to mint one.
 */
abstract class UuidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'credits.primary_key_type' => 'uuid',
        ]);
    }
}
