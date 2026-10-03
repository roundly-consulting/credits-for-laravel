<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Actions;

use RoundlyConsulting\Credits\Support\CreditsConfig;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exceptions\UnknownCurrency;

final readonly class ResolveBucketCurrencyAction
{
    /**
     * The currency a bucket is denominated in, or null for a plain-credits bucket.
     *
     * Resolved lazily against money's registry on every call — never at boot — so a custom
     * currency a host registers with `Currencies::register()` in its own provider's `boot()`
     * (which may run after this package's) is accepted.
     *
     * @throws UnknownCurrency when the mapped code is not registered
     * @throws InvalidMoneyConfiguration when `credits.currencies` is malformed
     */
    public function execute(?string $bucket = null): ?Currency
    {
        $bucket = $this->bucket($bucket);
        $currencies = config('credits.currencies', []);

        if (! is_array($currencies)) {
            throw InvalidMoneyConfiguration::invalid('credits.currencies', 'it must map bucket names to currency codes');
        }

        $code = $currencies[$bucket] ?? null;

        if ($code === null) {
            return null;
        }

        if (! is_string($code) || trim($code) === '') {
            throw InvalidMoneyConfiguration::invalid("credits.currencies.{$bucket}", 'it must be a currency code string');
        }

        return Currency::of($code);
    }

    private function bucket(?string $bucket): string
    {
        return $bucket ?? CreditsConfig::defaultBucket();
    }
}
