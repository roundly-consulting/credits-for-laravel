<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits;

use RoundlyConsulting\Credits\Commands\ModifyCreditsCommand;
use Acme\LaravelPackageTools\Package;
use Acme\LaravelPackageTools\PackageServiceProvider;

final class CreditsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('credits')
            ->hasConfigFile()
            ->hasMigration('create_credits_table')
            ->hasTranslations()
            ->hasCommand(ModifyCreditsCommand::class);
    }
}
