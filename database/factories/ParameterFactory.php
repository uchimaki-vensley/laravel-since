<?php

namespace Database\Factories;

use App\Models\Method;
use App\Models\Parameter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Parameter> */
class ParameterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'method_id' => Method::factory(),
            'position' => fake()->unique()->numberBetween(0, PHP_INT_MAX),
            'name' => 'value',
            'type' => 'string',
            'default_value' => null,
            'is_variadic' => false,
            'by_ref' => false,
            'docblock_type' => 'string',
        ];
    }
}
