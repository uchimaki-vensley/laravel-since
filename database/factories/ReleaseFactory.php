<?php

namespace Database\Factories;

use App\Infrastructure\Persistence\Eloquent\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Release> */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(0, 999);
        $version = sprintf('13.%d.%d', intdiv($number, 10), $number % 10);

        return [
            'package' => 'laravel/framework',
            'version' => $version,
            'tag' => "v{$version}",
            'released_at' => fake()->dateTimeBetween('-5 years'),
            'parsed_at' => now(),
        ];
    }
}
