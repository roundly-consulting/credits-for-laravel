<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Credits\Models\Credit;
use RoundlyConsulting\Credits\Support\CreditModel;
use RoundlyConsulting\Credits\Support\CreditsConfig;

/** @extends Factory<Credit> */
final class CreditFactory extends Factory
{
    /**
     * The configured `credits.model`, so a host subclass's factory builds the subclass.
     *
     * @return class-string<Credit>
     */
    public function modelName(): string
    {
        return CreditModel::class();
    }

    /** @return array<model-property<Credit>, mixed> */
    public function definition(): array
    {
        return [
            // The configured bucket, not the migration's column default.
            'bucket' => CreditsConfig::defaultBucket(),
            'amount' => $this->faker->numberBetween(-1000, 1000),
            'description' => $this->faker->optional()->sentence(),
            'meta' => null,
        ];
    }
}
