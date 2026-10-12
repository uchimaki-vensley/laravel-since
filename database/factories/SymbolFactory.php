<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\Symbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Symbol> */
class SymbolFactory extends Factory
{
    protected $model = Symbol::class;

    public function definition(): array
    {
        return [
            'fqcn' => 'App\\Types\\'.fake()->unique()->lexify('Type????'),
            'member' => fake()->lexify('method????'),
        ];
    }
}
