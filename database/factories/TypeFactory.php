<?php

namespace Database\Factories;

use App\Domain\Enums\TypeKind;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Type> */
class TypeFactory extends Factory
{
    protected $model = Type::class;

    public function definition(): array
    {
        return [
            'release_id' => Release::factory(),
            'fqcn' => 'App\\Types\\'.fake()->unique()->lexify('Type????'),
            'kind' => TypeKind::ClassType,
            'file_path' => 'src/Illuminate/Example.php',
            'is_internal' => false,
            'is_deprecated' => false,
        ];
    }
}
