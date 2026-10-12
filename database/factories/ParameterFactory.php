<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\Method;
use App\Infrastructure\Persistence\Eloquent\Models\Parameter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/** @extends Factory<Parameter> */
class ParameterFactory extends Factory
{
    protected $model = Parameter::class;

    public function configure(): static
    {
        return $this->sequence(fn (Sequence $sequence): array => ['position' => $sequence->index]);
    }

    public function definition(): array
    {
        return [
            'method_id' => Method::factory(),
            'position' => 0,
            'name' => 'value',
            'type' => 'string',
            'default_value' => null,
            'is_variadic' => false,
            'by_ref' => false,
            'docblock_type' => 'string',
        ];
    }
}
