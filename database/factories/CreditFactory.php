<?php

declare(strict_types=1);

namespace RoundlyConsulting\Credits\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Credits\Models\Credit;

/** @extends Factory<Credit> */
final class CreditFactory extends Factory
{
    protected $model = Credit::class;

    /** @return array<model-property<Credit>, mixed> */
    public function definition(): array
    {
        return [
            'amount' => $this->faker->numberBetween(-1000, 1000),
            'description' => $this->faker->optional()->sentence(),
            'meta' => null,
        ];
    }
}
