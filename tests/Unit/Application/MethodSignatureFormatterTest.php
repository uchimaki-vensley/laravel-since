<?php

namespace Tests\Unit\Application;

use App\Application\Changes\MethodSignatureFormatter;
use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\Visibility;
use PHPUnit\Framework\TestCase;

class MethodSignatureFormatterTest extends TestCase
{
    public function test_formats_a_declared_method_with_native_types_and_docblock_tags(): void
    {
        $method = new MethodDeclaration('incrementEach', Visibility::Public, false, null, 'int', [
            new ParameterDeclaration(0, 'columns', 'array', null, false, false, 'array<string, float|int|numeric-string>'),
            new ParameterDeclaration(1, 'extra', 'array', '[]', false, false, null),
        ], MethodSource::Declared, false, false);

        $this->assertSame(
            'incrementEach(array $columns, array $extra = []) [@param array<string, float|int|numeric-string> $columns, @return int]',
            MethodSignatureFormatter::format($method),
        );
    }

    public function test_formats_static_by_reference_and_variadic_parameters(): void
    {
        $method = new MethodDeclaration('swap', Visibility::Public, true, '?static', null, [
            new ParameterDeclaration(0, 'value', null, null, false, true, null),
            new ParameterDeclaration(1, 'rest', 'string', null, true, false, null),
        ], MethodSource::Declared, false, false);

        $this->assertSame('static swap(&$value, string ...$rest): ?static', MethodSignatureFormatter::format($method));
    }

    public function test_formats_a_docblock_method_with_docblock_types(): void
    {
        $method = new MethodDeclaration('make', Visibility::Public, true, null, '\Illuminate\Fixture\Widget', [
            new ParameterDeclaration(0, 'name', null, 'null', false, false, 'string|null'),
        ], MethodSource::Docblock, false, false);

        $this->assertSame(
            '@method static make(string|null $name = null): \Illuminate\Fixture\Widget',
            MethodSignatureFormatter::format($method),
        );
    }
}
