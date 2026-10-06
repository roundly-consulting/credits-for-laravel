<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Support\CreditsConfig;

final class ModifyCreditsCommand extends Command
{
    protected $signature = 'credits:modify
        {--amount= : The credit amount to apply: a non-zero integer, negative to deduct (required)}
        {--description= : An optional human-readable description}
        {--bucket= : The named bucket to apply the change to (defaults to the configured bucket)}
        {--allow-overdraft : Permit deductions that drive the balance below credits.minimum_balance}';

    protected $description = 'Modify credits on the entities resolved by the credits.modifiable config';

    /**
     * `--amount` is required and must be a non-zero integer. Each resolved entity is changed
     * through `Credits::for($entity)`, so the same overdraft guard and event apply — and
     * `Credits::fake()` records it. An entity the guard refuses is reported and skipped, never
     * aborting the run half-applied; any refusal makes the command exit non-zero once every
     * entity has been visited.
     */
    public function handle(CreditsManager $credits): int
    {
        $amountOption = $this->option('amount');

        // Required, and never 0: a run without it used to write a 0-amount row (and fire
        // CreditsModified) for every resolved entity. Checked before any resolver runs.
        if ($amountOption === null) {
            $this->error('The --amount option is required.');

            return self::FAILURE;
        }

        if (! is_numeric($amountOption) || (string) (int) $amountOption !== (string) $amountOption) {
            $this->error('The --amount option must be an integer.');

            return self::FAILURE;
        }

        $amount = (int) $amountOption;

        if ($amount === 0) {
            $this->error('The --amount option must not be 0.');

            return self::FAILURE;
        }
        $description = $this->option('description');
        $bucket = $this->option('bucket');
        $allowOverdraft = (bool) $this->option('allow-overdraft');

        $count = 0;
        $refused = 0;

        $bucket = is_string($bucket) && $bucket !== '' ? $bucket : null;

        $modify = function (mixed $entity) use ($credits, $amount, $description, $bucket, $allowOverdraft, &$count, &$refused): void {
            if (! $entity instanceof Model || ! $entity instanceof Creditable) {
                $this->warn('Skipped a resolved entity that is not a Creditable model.');

                return;
            }

            $scope = $credits->for($entity)->allowOverdraft($allowOverdraft);

            try {
                ($bucket === null ? $scope : $scope->bucket($bucket))->modify(
                    $amount,
                    is_string($description) ? $description : null,
                    ['info' => 'Credits modified by credits:modify command.'],
                );
            } catch (InsufficientCreditsException $exception) {
                $refused++;
                $this->warn('Refused '.$entity::class.' #'.$entity->getKey().': '.$exception->getMessage());

                return;
            }

            $count++;
        };

        foreach (CreditsConfig::modifiable() as $callback) {
            $callback($modify);
        }

        $this->info("Modified credits on {$count} ".($count === 1 ? 'entity' : 'entities').'.');

        if ($refused > 0) {
            $this->error("Refused {$refused} ".($refused === 1 ? 'entity' : 'entities').' with insufficient credits.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
