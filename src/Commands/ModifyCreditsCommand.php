<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Commands;

use Closure;
use Illuminate\Console\Command;
use RoundlyConsulting\Credits\Interfaces\Creditable;

final class ModifyCreditsCommand extends Command
{
    protected $signature = 'credits:modify
        {--amount=0 : The credit amount to apply (may be negative)}
        {--description= : An optional human-readable description}';

    protected $description = 'Modify credits on the entities resolved by the credits.modifiable config';

    public function handle(): int
    {
        $description = $this->option('description');

        $modify = function (Creditable $creditable) use ($description): void {
            $creditable->modifyCredits(
                amount: (int) $this->option('amount'),
                description: is_string($description) ? $description : null,
                meta: [
                    'info' => 'Credits modified by credits:modify command.',
                ],
            );
        };

        /** @var iterable<Closure(Closure(Creditable): void): void> $modifiable */
        $modifiable = config('credits.modifiable', []);

        foreach ($modifiable as $callback) {
            $callback($modify);
        }

        return self::SUCCESS;
    }
}
