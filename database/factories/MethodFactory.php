<?php

namespace Database\Factories;

use App\Domain\Enums\MethodSource;
use App\Domain\Enums\Visibility;
use App\Infrastructure\Persistence\Eloquent\Models\Method;
use App\Infrastructure\Persistence\Eloquent\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Method> */
class MethodFactory extends Factory
{
    protected $model = Method::class;

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
            'source' => MethodSource::Declared,
            'is_internal' => false,
            'is_deprecated' => false,
        ];
    }
}
