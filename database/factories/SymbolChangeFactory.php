<?php

namespace Database\Factories;

use App\Enums\SymbolChangeKind;
use App\Models\Release;
use App\Models\Symbol;
use App\Models\SymbolChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SymbolChange> */
class SymbolChangeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'symbol_id' => Symbol::factory(),
            'release_id' => Release::factory(),
            'kind' => SymbolChangeKind::Added,
            'before' => null,
            'after' => ['signature' => 'function method(): string'],
        ];
    }
}
