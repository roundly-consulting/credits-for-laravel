<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use RoundlyConsulting\Credits\Enums\ResetPeriod;
use RoundlyConsulting\Credits\Interfaces\Creditable;

/**
 * The per-period markers that make a bucket reset idempotent: one `credit_resets` row per
 * owner, bucket and period key, on the ledger's connection. Never read for a balance.
 *
 * @internal
 */
final class CreditResets
{
    public const string TABLE = 'credit_resets';

    public const int MAX_KEY_LENGTH = 64;

    /**
     * The period key: a ResetPeriod's key for `$now`, or an explicit key.
     *
     * @throws InvalidArgumentException when it is blank or too long
     */
    public static function key(ResetPeriod|string $period, CarbonInterface $now): string
    {
        $key = $period instanceof ResetPeriod ? $period->key($now) : trim($period);

        if ($key === '' || mb_strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InvalidArgumentException('A reset period key must be 1 to '.self::MAX_KEY_LENGTH." characters, [{$key}] given.");
        }

        return $key;
    }

    /**
     * Record the period's reset; false when it was recorded already. Run under the owner lock.
     */
    public static function claim(ConnectionInterface $connection, Model&Creditable $creditable, string $bucket, string $key, int $to): bool
    {
        return $connection->table(self::TABLE)->insertOrIgnore([
            'creditable_type' => $creditable->getMorphClass(),
            'creditable_id' => $creditable->getKey(),
            'bucket' => $bucket,
            'period' => $key,
            'reset_to' => $to,
            'created_at' => now(),
        ]) > 0;
    }

    /**
     * Whether the period's reset was recorded.
     */
    public static function claimed(ConnectionInterface $connection, Model&Creditable $creditable, string $bucket, string $key): bool
    {
        return self::row($connection, $creditable, $bucket, $key)->exists();
    }

    /**
     * Point the marker at the delta row the reset wrote.
     */
    public static function link(ConnectionInterface $connection, Model&Creditable $creditable, string $bucket, string $key, mixed $creditId): void
    {
        self::row($connection, $creditable, $bucket, $key)->update(['credit_id' => $creditId]);
    }

    private static function row(ConnectionInterface $connection, Model&Creditable $creditable, string $bucket, string $key): Builder
    {
        return $connection->table(self::TABLE)
            ->where('creditable_type', $creditable->getMorphClass())
            ->where('creditable_id', $creditable->getKey())
            ->where('bucket', $bucket)
            ->where('period', $key);
    }
}
