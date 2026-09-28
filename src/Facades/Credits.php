<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Credits\CreditsManager;
use RoundlyConsulting\Credits\Testing\CreditsFake;

/**
 * @method static \RoundlyConsulting\Credits\Handles\CreditsScope for(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Credits\Interfaces\Creditable $owner)
 * @method static string format(int $amount, ?int $scale = null, ?\RoundingMode $rounding = null, ?int $storedScale = null)
 * @method static ?\RoundlyConsulting\Money\Currency currency(?string $bucket = null)
 * @method static int balance(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Credits\Interfaces\Creditable $owner, ?string $bucket = null, ?\Carbon\CarbonInterface $at = null)
 * @method static int total(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Credits\Interfaces\Creditable $owner, ?array<int, string> $buckets = null, ?\Carbon\CarbonInterface $at = null)
 * @method static \RoundlyConsulting\Credits\Models\Credit modify(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Credits\Interfaces\Creditable $owner, \RoundlyConsulting\Credits\DataTransferObjects\CreditChangeData $data)
 * @method static ?\RoundlyConsulting\Credits\Models\Credit setTo(\Illuminate\Database\Eloquent\Model&\RoundlyConsulting\Credits\Interfaces\Creditable $owner, int $amount, ?string $description = null, ?array<string, mixed> $meta = null, bool $allowOverdraft = false, ?string $bucket = null)
 *
 * @see CreditsManager
 */
final class Credits extends Facade
{
    /**
     * Swap the manager for a recorder that writes nothing — to the facade and to every
     * constructor-injected CreditsManager, the HasCredits trait and `credits:modify`
     * included — and return it for assertions.
     */
    public static function fake(): CreditsFake
    {
        $fake = app(CreditsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return CreditsManager::class;
    }
}
