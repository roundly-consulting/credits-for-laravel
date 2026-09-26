<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Credits\CreditsServiceProvider;
use RoundlyConsulting\Money\MoneyServiceProvider;
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
        return [MoneyServiceProvider::class, CreditsServiceProvider::class];
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

    /**
     * Two custom money currencies, registered the way a host does it — through
     * `money.currencies.custom`, before the providers boot — so the denominated-bucket
     * suite can map buckets to loyalty points (`PTS`, exponent 0) and to an 18-decimal
     * crypto unit (`ETH`), next to the bundled ISO list.
     *
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'money.currencies.custom' => [
                'PTS' => ['exponent' => 0, 'name' => 'Loyalty points', 'symbol' => 'pts'],
                'ETH' => ['exponent' => 18, 'symbol' => 'Ξ'],
            ],
        ]);
    }
}
