<?php

namespace Tests\Feature;

use App\Enums\SymbolChangeKind;
use App\Enums\TypeKind;
use App\Enums\Visibility;
use App\Models\Method;
use App\Models\Parameter;
use App\Models\Release;
use App\Models\Symbol;
use App\Models\SymbolChange;
use App\Models\Type;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_type_method_and_parameter_relationships(): void
    {
        $release = Release::factory()->create();
        $release->refresh();
        $type = Type::factory()->for($release)->create();
        $method = Method::factory()->for($type)->create();
        $parameters = Parameter::factory()->count(2)->for($method)->create();

        $this->assertTrue($release->types->contains($type));
        $this->assertInstanceOf(DateTimeInterface::class, $release->released_at);
        $this->assertInstanceOf(DateTimeInterface::class, $release->parsed_at);
        $this->assertTrue($type->release?->is($release) ?? false);
        $this->assertTrue($type->methods->contains($method));
        $this->assertTrue($method->type?->is($type) ?? false);
        $this->assertCount(2, $parameters);
        $this->assertCount(2, $method->parameters);
        $this->assertCount(2, $parameters->pluck('position')->unique());
        foreach ($parameters as $parameter) {
            $this->assertTrue($parameter->method?->is($method) ?? false);
        }
        $this->assertSame(Visibility::Public, $method->refresh()->visibility);
    }

    public function test_symbol_change_relationships_and_enum_casts(): void
    {
        $release = Release::factory()->create();
        $type = Type::factory()->for($release)->create(['kind' => TypeKind::InterfaceType]);
        $symbol = Symbol::factory()->create();
        $change = SymbolChange::factory()->for($symbol)->for($release)->create([
            'kind' => SymbolChangeKind::SignatureChanged,
            'before' => ['signature' => 'function method(): int'],
            'after' => ['signature' => 'function method(): string'],
        ]);

        $type->refresh();
        $change->refresh();

        $this->assertSame(TypeKind::InterfaceType, $type->kind);
        $this->assertSame(SymbolChangeKind::SignatureChanged, $change->kind);
        $this->assertTrue($symbol->changes->contains($change));
        $this->assertTrue($release->symbolChanges->contains($change));
        $this->assertTrue($change->symbol?->is($symbol) ?? false);
        $this->assertTrue($change->release?->is($release) ?? false);
        $this->assertSame(['signature' => 'function method(): int'], $change->before);
        $this->assertSame(['signature' => 'function method(): string'], $change->after);
        $this->assertDatabaseHas('types', ['id' => $type->id, 'kind' => 'interface']);
        $this->assertDatabaseHas('symbol_changes', ['id' => $change->id, 'kind' => 'signature_changed']);
    }

    public function test_enum_values_match_the_schema(): void
    {
        $this->assertSame(
            ['class', 'interface', 'trait', 'enum'],
            array_map(fn (TypeKind $kind): string => $kind->value, TypeKind::cases()),
        );
        $this->assertSame(
            ['public', 'protected', 'private'],
            array_map(fn (Visibility $visibility): string => $visibility->value, Visibility::cases()),
        );
        $this->assertSame(
            ['added', 'removed', 'signature_changed', 'deprecated'],
            array_map(fn (SymbolChangeKind $kind): string => $kind->value, SymbolChangeKind::cases()),
        );
    }
}
