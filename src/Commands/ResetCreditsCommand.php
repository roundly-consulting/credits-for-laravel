<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Commands;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Enums\ResetPeriod;
use RoundlyConsulting\Credits\Exceptions\InsufficientCreditsException;
use RoundlyConsulting\Credits\Interfaces\Creditable;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\Credits\Support\CreditResets;
use RoundlyConsulting\Credits\Support\CreditsConfig;
use Throwable;

final class ResetCreditsCommand extends Command
{
    protected $signature = 'credits:reset
        {bucket* : The buckets to reset}
        {--period=monthly : daily, weekly, monthly or yearly — the period each owner\'s bucket is reset once in}
        {--key= : An explicit period key instead of --period (e.g. 2026-11)}
        {--to=0 : The balance to reset each bucket to}
        {--timezone= : The timezone the period is read in (defaults to app.timezone)}
        {--description= : An optional description for the delta rows}
        {--allow-overdraft : Reset even when held credits would take the available balance below the minimum}
        {--resolvers : Reset the owners the credits.modifiable resolvers yield, instead of every owner with rows in the bucket}';

    protected $description = 'Reset credit buckets to a balance, once per period';

    /**
     * The period key is read once, so a run that straddles midnight resets every owner into
     * the same period. Each owner and bucket is reset through `Credits::for($owner)`, at most
     * once per key — a second run in the same period writes nothing. A refused owner is
     * reported and skipped (its next run retries it) and makes the command exit non-zero.
     */
    public function handle(CreditsManager $credits): int
    {
        $key = $this->periodKey();
        // A string from the console; `Artisan::call()` may hand over an int as given.
        $to = $this->option('to');
        $to = is_scalar($to) ? (string) $to : '';

        if ($key === null) {
            return self::FAILURE;
        }

        if (! preg_match('/^-?\d+$/', $to) || (string) (int) $to !== $to) {
            $this->error('The --to option must be an integer.');

            return self::FAILURE;
        }

        /** @var list<string> $buckets */
        $buckets = array_values(array_unique(array_filter((array) $this->argument('bucket'), static fn (string $bucket): bool => $bucket !== '')));
        $description = $this->option('description');
        $allowOverdraft = (bool) $this->option('allow-overdraft');
        $counts = ['reset' => 0, 'skipped' => 0, 'refused' => 0];

        foreach ($buckets as $bucket) {
            $reset = function (mixed $owner) use ($credits, $bucket, $key, $to, $description, $allowOverdraft, &$counts): void {
                if (! $owner instanceof Model || ! $owner instanceof Creditable) {
                    $this->warn('Skipped a resolved entity that is not a Creditable model.');

                    return;
                }

                try {
                    $result = $credits->for($owner)->bucket($bucket)->allowOverdraft($allowOverdraft)
                        ->reset($key, (int) $to, is_string($description) ? $description : null);
                } catch (InsufficientCreditsException $exception) {
                    $counts['refused']++;
                    $this->warn("Refused {$owner->getMorphClass()} #{$owner->getKey()} [{$bucket}]: {$exception->getMessage()}");

                    return;
                }

                $counts[$result->reset ? 'reset' : 'skipped']++;
            };

            $this->option('resolvers') ? $this->eachResolved($reset) : $this->eachHolder($bucket, $reset);
        }

        $this->info("Reset {$counts['reset']}, already reset in period [{$key}] {$counts['skipped']}.");

        if ($counts['refused'] > 0) {
            $this->error("Refused {$counts['refused']} with insufficient credits.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function periodKey(): ?string
    {
        $explicit = $this->option('key');
        $timezone = $this->option('timezone');

        try {
            if (is_string($explicit) && $explicit !== '') {
                return CreditResets::key($explicit, CarbonImmutable::now());
            }

            $period = $this->option('period');
            $period = ResetPeriod::tryFrom(is_string($period) ? $period : '');

            if ($period === null) {
                $this->error('The --period option must be one of: daily, weekly, monthly, yearly.');

                return null;
            }

            return $period->key(CarbonImmutable::now(is_string($timezone) && $timezone !== '' ? $timezone : null));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return null;
        }
    }

    /**
     * @param  Closure(mixed): void  $reset
     */
    private function eachResolved(Closure $reset): void
    {
        foreach (CreditsConfig::modifiable() as $resolver) {
            $resolver($reset);
        }
    }

    /**
     * Every owner with a ledger row in the bucket, loaded through its morph type.
     *
     * @param  Closure(mixed): void  $reset
     */
    private function eachHolder(string $bucket, Closure $reset): void
    {
        CreditModel::class()::query()
            ->bucket($bucket)
            ->select(['creditable_type', 'creditable_id'])
            ->distinct()
            ->orderBy('creditable_type')
            ->orderBy('creditable_id')
            ->toBase()
            ->chunk(500, function (iterable $rows) use ($reset): void {
                foreach ($rows as $row) {
                    $class = Relation::getMorphedModel($row->creditable_type) ?? $row->creditable_type;

                    $owner = is_string($class) && is_subclass_of($class, Model::class)
                        ? $class::query()->find($row->creditable_id)
                        : null;

                    if ($owner === null) {
                        $this->warn("Skipped {$row->creditable_type} #{$row->creditable_id}: the owner no longer exists.");

                        continue;
                    }

                    $reset($owner);
                }
            });
    }
}
