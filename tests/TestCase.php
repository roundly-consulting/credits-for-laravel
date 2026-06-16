<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Credits\CreditsServiceProvider;

abstract class TestCase extends Orchestra
{
    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [CreditsServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }
}
