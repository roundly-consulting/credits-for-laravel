<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests\Fixtures;

use RoundlyConsulting\Credits\Tests\TestCase;

/**
 * The suite's base case with `credits.primary_key_type` set to `ulid` BEFORE the providers
 * boot and before the migration runs.
 *
 * @see UuidKeyTestCase
 */
abstract class UlidKeyTestCase extends TestCase
{
    /** @return array<string, mixed> */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'credits.primary_key_type' => 'ulid',
        ]);
    }
}
