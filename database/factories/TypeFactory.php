<?php

namespace Database\Factories;

use App\Enums\TypeKind;
use App\Models\Release;
use App\Models\Type;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Type> */
class TypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'release_id' => Release::factory(),
            'fqcn' => 'App\\Types\\'.fake()->unique()->lexify('Type????'),
            'kind' => TypeKind::ClassType,
            'file_path' => 'src/Illuminate/Example.php',
        ];
    }
}
