<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Credits\Enums\CreditHoldStatus;
use RoundlyConsulting\Credits\Models\CreditHold;
use RoundlyConsulting\Credits\Support\CreditsConfig;

/** @extends Factory<CreditHold> */
final class CreditHoldFactory extends Factory
{
    protected $model = CreditHold::class;

    /** @return array<model-property<CreditHold>, mixed> */
    public function definition(): array
    {
        return [
            'bucket' => CreditsConfig::defaultBucket(),
            'amount' => $this->faker->numberBetween(1, 1000),
            'status' => CreditHoldStatus::Held,
            'description' => $this->faker->optional()->sentence(),
            'meta' => null,
            'expires_at' => null,
        ];
    }
}
