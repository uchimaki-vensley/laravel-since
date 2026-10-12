<?php

namespace Database\Factories;

use App\Enums\Visibility;
use App\Models\Method;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Method> */
class MethodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type_id' => Type::factory(),
            'name' => fake()->unique()->lexify('method????'),
            'visibility' => Visibility::Public,
            'is_static' => false,
            'return_type' => 'string',
            'docblock_return_type' => 'string',
            'signature_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
