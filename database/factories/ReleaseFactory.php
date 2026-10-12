<?php

namespace Database\Factories;

use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Release> */
class ReleaseFactory extends Factory
{
    public function definition(): array
    {
        $version = fake()->unique()->numerify('13.##.#');

        return [
            'package' => 'laravel/framework',
            'version' => $version,
            'tag' => "v{$version}",
            'released_at' => fake()->dateTimeBetween('-5 years'),
            'parsed_at' => now(),
        ];
    }
}
