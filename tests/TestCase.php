<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Credits\CreditsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider credits hard-requires, in registration order. A host auto-discovers
     * these; the suite must list them or the test environment is a fiction.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [CreditsServiceProvider::class];
    }

    /**
     * The ledger migration, named by provider class (never by filename), plus the
     * host-owned `users` fixture table the Creditable entity lives in.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            CreditsServiceProvider::class,
            __DIR__.'/database/migrations',
        ];
    }
}
