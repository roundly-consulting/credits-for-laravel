<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits;

use RoundlyConsulting\Credits\Commands\ModifyCreditsCommand;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Support\RoundingModes;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class CreditsServiceProvider extends PackageServiceProvider
{
    use RegistersBlueprintMacros;

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
                // Surfaced deliberately: a non-bigint id cannot be held by another
                // package's `morphs()` column on a strict engine, so a host that has
                // flipped this needs to see it without reading a migration.
                'Primary key type' => KeyType::fromConfig('credits.primary_key_type')->value,
                // The outbound axis: the key type of the creditable morph column. A
                // different axis from the credits table's own id — see the config.
                'Creditable key type' => KeyType::fromConfig('credits.key_type')->value,
                'Overdraft' => config('credits.allow_overdraft', false) === true ? 'ALLOWED' : 'BLOCKED',
                'Minimum balance' => (string) (int) config('credits.minimum_balance', 0),
                'Default bucket' => (string) config('credits.default_bucket', 'default'),
                'Scale' => (string) (int) config('credits.scale', 0),
                'Rounding' => self::rounding(),
                'Modifiable resolvers' => self::modifiableResolvers(),
            ]);
    }

    public function boot(): void
    {
        parent::boot();

        // The credits migration keys its `creditable` polymorphic column through the
        // toolkit's `morphKey` macro, so it must exist before the migration runs.
        // Registration is idempotent — the toolkit guards it with `hasMacro()`.
        $this->registerBlueprintMacros();
    }

    /**
     * The configured display rounding mode, normalised to its config spelling. A
     * misconfigured value renders as INVALID rather than failing the whole `about` command —
     * the display helpers throw on it at first use.
     */
    private static function rounding(): string
    {
        try {
            return RoundingModes::toValue(RoundingModes::fromValue(config('credits.rounding'), 'credits.rounding'));
        } catch (InvalidMoneyConfiguration) {
            return 'INVALID';
        }
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
