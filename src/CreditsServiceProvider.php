<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Credits\Commands\ModifyCreditsCommand;

final class CreditsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/credits.php', 'credits');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'credits');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ModifyCreditsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/credits.php' => config_path('credits.php'),
            ], 'credits-config');

            $this->publishes([
                __DIR__.'/../database/migrations/create_credits_table.php' => $this->publishableMigrationPath(),
            ], 'credits-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/credits'),
            ], 'credits-translations');
        }
    }

    /**
     * Stamp the published migration with a current timestamp so it runs after
     * any migrations already present in the host application.
     */
    private function publishableMigrationPath(): string
    {
        return database_path('migrations/'.date('Y_m_d_His').'_create_credits_table.php');
    }
}
