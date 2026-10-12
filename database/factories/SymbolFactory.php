<?php

namespace Database\Factories;

use App\Models\Symbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Symbol> */
class SymbolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fqcn' => 'App\\Types\\'.fake()->lexify('Type????'),
            'member' => fake()->lexify('method????'),
        ];
    }
}
