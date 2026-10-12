<?php

namespace Database\Factories;

use App\Domain\Enums\SymbolChangeKind;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Symbol;
use App\Infrastructure\Persistence\Eloquent\Models\SymbolChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SymbolChange> */
class SymbolChangeFactory extends Factory
{
    protected $model = SymbolChange::class;

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
