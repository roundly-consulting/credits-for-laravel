<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits;

use RoundlyConsulting\Credits\Commands\ModifyCreditsCommand;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class CreditsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('credits')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasTranslations()
            ->hasCommands([
                ModifyCreditsCommand::class,
            ])
            ->contributesToAbout(static fn (): array => [
                'Model' => class_basename(CreditModel::class()),
                'Overdraft' => config('credits.allow_overdraft', false) === true ? 'ALLOWED' : 'BLOCKED',
                'Minimum balance' => (string) (int) config('credits.minimum_balance', 0),
                'Default bucket' => (string) config('credits.default_bucket', 'default'),
                'Scale' => (string) (int) config('credits.scale', 0),
                'Modifiable resolvers' => self::modifiableResolvers(),
            ]);
    }

    /**
     * The `credits.modifiable` entries are host closures that walk the host's own
     * entities, so the section reports how many are registered and never anything
     * about what they resolve.
     */
    private static function modifiableResolvers(): string
    {
        $modifiable = config('credits.modifiable', []);
        $count = is_countable($modifiable) ? count($modifiable) : 0;

        return $count === 0 ? 'NONE' : $count.' registered';
    }
}
