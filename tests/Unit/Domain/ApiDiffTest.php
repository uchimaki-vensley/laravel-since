<?php

namespace Tests\Unit\Domain;

use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Changes\ApiDiff;
use App\Domain\Changes\MethodChange;
use App\Domain\Changes\SymbolName;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\SymbolChangeKind;
use App\Domain\Enums\TypeKind;
use App\Domain\Enums\Visibility;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ApiDiffTest extends TestCase
{
    public function test_detects_added_removed_and_changed_methods_in_symbol_order(): void
    {
        $before = [
            self::type('Illuminate\Database\Eloquent\Builder', [
                self::method('increment'),
                self::method('decrement'),
                self::method('where'),
            ]),
            self::type('Illuminate\Support\Gone', [self::method('vanish')]),
        ];
        $after = [
            self::type('Illuminate\Database\Eloquent\Builder', [
                self::method('increment', returnType: 'int'),
                self::method('incrementEach'),
                self::method('where'),
                self::method('decrement', deprecated: true),
            ]),
        ];

        $this->assertSame([
            ['Illuminate\Database\Eloquent\Builder::decrement', SymbolChangeKind::Deprecated],
            ['Illuminate\Database\Eloquent\Builder::increment', SymbolChangeKind::SignatureChanged],
            ['Illuminate\Database\Eloquent\Builder::incrementEach', SymbolChangeKind::Added],
            ['Illuminate\Support\Gone::vanish', SymbolChangeKind::Removed],
        ], self::summary(ApiDiff::between($before, $after)));
    }

    public function test_keeps_the_declarations_before_and_after_the_change(): void
    {
        $old = self::method('increment');
        $new = self::method('increment', returnType: 'int');

        [$change] = ApiDiff::between([self::type('A', [$old])], [self::type('A', [$new])]);

        $this->assertSame($old, $change->before);
        $this->assertSame($new, $change->after);
        $this->assertEquals(new SymbolName('A', 'increment'), $change->symbol);
    }

    public function test_reports_a_signature_change_and_a_deprecation_in_the_same_release(): void
    {
        $changes = ApiDiff::between(
            [self::type('A', [self::method('run')])],
            [self::type('A', [self::method('run', returnType: 'int', deprecated: true)])],
        );

        $this->assertSame([
            ['A::run', SymbolChangeKind::SignatureChanged],
            ['A::run', SymbolChangeKind::Deprecated],
        ], self::summary($changes));
    }

    public function test_ignores_markers_other_than_a_new_deprecation(): void
    {
        $changes = ApiDiff::between(
            [self::type('A', [self::method('run', deprecated: true), self::method('stop')])],
            [self::type('A', [self::method('run'), self::method('stop', source: MethodSource::Docblock)])],
        );

        $this->assertSame([], $changes);
    }

    public function test_treats_names_that_differ_only_in_case_as_the_same_method(): void
    {
        $changes = ApiDiff::between(
            [self::type('Illuminate\Support\STR', [self::method('isascii')])],
            [self::type('Illuminate\Support\Str', [self::method('isAscii', returnType: 'bool')])],
        );

        $this->assertSame([['Illuminate\Support\Str::isAscii', SymbolChangeKind::SignatureChanged]], self::summary($changes));
    }

    public function test_lists_the_symbols_of_a_snapshot(): void
    {
        $this->assertEquals(
            [new SymbolName('A', 'run'), new SymbolName('B', 'stop')],
            ApiDiff::symbols([self::type('A', [self::method('run')]), self::type('B', [self::method('stop')])]),
        );
    }

    public function test_a_change_needs_the_declarations_its_kind_requires(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MethodChange(new SymbolName('A', 'run'), SymbolChangeKind::Added, self::method('run'), null);
    }

    /**
     * @param  list<MethodChange>  $changes
     * @return list<array{string, SymbolChangeKind}>
     */
    private static function summary(array $changes): array
    {
        return array_map(static fn (MethodChange $change) => [(string) $change->symbol, $change->kind], $changes);
    }

    /**
     * @param  list<MethodDeclaration>  $methods
     */
    private static function type(string $fqcn, array $methods): TypeDeclaration
    {
        return new TypeDeclaration($fqcn, TypeKind::ClassType, 'src/Illuminate/Example.php', false, false, $methods);
    }

    private static function method(
        string $name,
        ?string $returnType = null,
        bool $deprecated = false,
        MethodSource $source = MethodSource::Declared,
    ): MethodDeclaration {
        return new MethodDeclaration($name, Visibility::Public, false, $returnType, null, [
            new ParameterDeclaration(0, 'columns', 'array', null, false, false, null),
        ], $source, false, $deprecated);
    }
}
