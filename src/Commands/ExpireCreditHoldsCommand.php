<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Support\CreditsConfig;

final class ExpireCreditHoldsCommand extends Command
{
    protected $signature = 'credits:expire-holds
        {--connection= : The connection the holds live on (defaults to the ledger model\'s, else the default)}';

    protected $description = 'Mark credit holds past their expiry as expired';

    /**
     * Safe to schedule as often as you like and on several servers: each hold is expired
     * under its owner's lock, once. With holds off it does nothing and succeeds.
     */
    public function handle(CreditsManager $credits): int
    {
        if (! CreditsConfig::holdsEnabled()) {
            $this->info('Credit holds are disabled; nothing to expire.');

            return self::SUCCESS;
        }

        $connection = $this->option('connection');
        $expired = $credits->expireHolds(connection: is_string($connection) && $connection !== '' ? $connection : null);

        $this->info("Expired {$expired} ".($expired === 1 ? 'hold' : 'holds').'.');

        return self::SUCCESS;
    }
}
