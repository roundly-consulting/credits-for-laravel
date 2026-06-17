<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\Interfaces\Creditable;

final class ModifyCreditsCommand extends Command
{
    protected $signature = 'credits:modify
        {--amount=0 : The credit amount to apply (may be negative)}
        {--description= : An optional human-readable description}
        {--bucket= : The named bucket to apply the change to (defaults to the configured bucket)}
        {--allow-overdraft : Permit deductions that drive the balance below zero}';

    protected $description = 'Modify credits on the entities resolved by the credits.modifiable config';

    public function handle(): int
    {
        $amountOption = $this->option('amount');

        if (! is_numeric($amountOption) || (string) (int) $amountOption !== (string) $amountOption) {
            $this->error('The --amount option must be an integer.');

            return self::FAILURE;
        }

        $amount = (int) $amountOption;
        $description = $this->option('description');
        $bucket = $this->option('bucket');
        $allowOverdraft = (bool) $this->option('allow-overdraft');

        $count = 0;

        $modify = function (mixed $entity) use ($amount, $description, $bucket, $allowOverdraft, &$count): void {
            if (! $entity instanceof Model || ! $entity instanceof Creditable) {
                $this->warn('Skipped a resolved entity that is not a Creditable model.');

                return;
            }

            $entity->modifyCredits(
                amount: $amount,
                description: is_string($description) ? $description : null,
                meta: ['info' => 'Credits modified by credits:modify command.'],
                allowOverdraft: $allowOverdraft,
                bucket: is_string($bucket) && $bucket !== '' ? $bucket : null,
            );

            $count++;
        };

        /** @var iterable<Closure(Closure(mixed): void): void> $modifiable */
        $modifiable = config('credits.modifiable', []);

        foreach ($modifiable as $callback) {
            $callback($modify);
        }

        $this->info("Modified credits on {$count} ".($count === 1 ? 'entity' : 'entities').'.');

        return self::SUCCESS;
    }
}
