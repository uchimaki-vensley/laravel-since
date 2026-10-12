<?php

namespace Tests\Feature;

use App\Domain\Enums\SymbolChangeKind;
use App\Domain\Enums\TypeKind;
use App\Domain\Enums\Visibility;
use App\Infrastructure\Persistence\Eloquent\Models\Method;
use App\Infrastructure\Persistence\Eloquent\Models\Parameter;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Symbol;
use App\Infrastructure\Persistence\Eloquent\Models\SymbolChange;
use App\Infrastructure\Persistence\Eloquent\Models\Type;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame([0, 1], $parameters->pluck('position')->all());
        foreach ($parameters as $parameter) {
            $this->assertTrue($parameter->method?->is($method) ?? false);
        }
        $this->assertSame(Visibility::Public, $method->refresh()->visibility);
    }

    public function test_method_fillable_attribute_allows_model_fields(): void
    {
        $method = new Method(['name' => 'resolve']);

        $this->assertSame('resolve', $method->name);
    }

    public function test_symbol_change_relationships_and_enum_casts(): void
    {
        $release = Release::factory()->create();
        $type = Type::factory()->for($release)->create(['kind' => TypeKind::InterfaceType]);
        $symbols = Symbol::factory()->count(2)->create();
        $symbol = $symbols->firstOrFail();
        $change = SymbolChange::factory()->for($symbol)->for($release)->create([
            'kind' => SymbolChangeKind::SignatureChanged,
            'before' => ['signature' => 'function method(): int'],
            'after' => ['signature' => 'function method(): string'],
        ]);

        $type->refresh();
        $change->refresh();

        $this->assertSame(TypeKind::InterfaceType, $type->kind);
        $this->assertCount(2, $symbols->pluck('fqcn')->unique());
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

    public function test_type_rows_are_unique_per_release_and_fqcn(): void
    {
        $release = Release::factory()->create();
        $type = Type::factory()->for($release)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Type::factory()->for($release)->create(['fqcn' => $type->fqcn]);
    }

    public function test_symbol_changes_are_unique_per_symbol_release_and_kind(): void
    {
        $release = Release::factory()->create();
        $symbol = Symbol::factory()->create();
        $attributes = ['kind' => SymbolChangeKind::Added];

        SymbolChange::factory()->for($symbol)->for($release)->create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        SymbolChange::factory()->for($symbol)->for($release)->create($attributes);
    }

    public function test_release_deletion_cascades_through_dependent_rows(): void
    {
        $release = Release::factory()->create();
        $type = Type::factory()->for($release)->create();
        $method = Method::factory()->for($type)->create();
        Parameter::factory()->for($method)->create();
        $symbol = Symbol::factory()->create();
        SymbolChange::factory()->for($symbol)->for($release)->create();

        $release->delete();

        $this->assertDatabaseCount('types', 0);
        $this->assertDatabaseCount('methods', 0);
        $this->assertDatabaseCount('parameters', 0);
        $this->assertDatabaseCount('symbol_changes', 0);
        $this->assertDatabaseCount('symbols', 1);
    }

    /** @return array<string, array{string}> */
    public static function remainingUniqueKeys(): array
    {
        return [
            'release package and version' => ['release'],
            'method type and name' => ['method'],
            'parameter method and position' => ['parameter'],
            'symbol fqcn and member' => ['symbol'],
        ];
    }

    #[DataProvider('remainingUniqueKeys')]
    public function test_remaining_unique_keys_reject_duplicates(string $key): void
    {
        $release = Release::factory()->create();
        $type = Type::factory()->for($release)->create();
        $method = Method::factory()->for($type)->create();
        $parameter = Parameter::factory()->for($method)->create();
        $symbol = Symbol::factory()->create();

        $duplicate = match ($key) {
            'release' => fn () => Release::factory()->create([
                'package' => $release->package,
                'version' => $release->version,
            ]),
            'method' => fn () => Method::factory()->for($type)->create(['name' => $method->name]),
            'parameter' => fn () => Parameter::factory()->for($method)->create(['position' => $parameter->position]),
            'symbol' => fn () => Symbol::factory()->create([
                'fqcn' => $symbol->fqcn,
                'member' => $symbol->member,
            ]),
            default => throw new \InvalidArgumentException("Unknown unique key: {$key}"),
        };

        $this->expectException(UniqueConstraintViolationException::class);

        $duplicate();
    }
}
