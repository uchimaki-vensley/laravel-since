<?php

namespace Tests\Unit\Application;

use App\Application\Site\SignatureFormatter;
use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\Visibility;
use PHPUnit\Framework\TestCase;

class SignatureFormatterTest extends TestCase
{
    public function test_formats_declared_methods_with_native_types_and_docblock_lines(): void
    {
        $method = new MethodDeclaration('incrementEach', Visibility::Public, false, 'int', null, [
            new ParameterDeclaration(0, 'columns', 'array', null, false, false, 'array<string, int>'),
            new ParameterDeclaration(1, 'extra', 'array', '[]', false, false, null),
            new ParameterDeclaration(2, 'value', null, null, false, true, null),
            new ParameterDeclaration(3, 'rest', 'string', null, true, false, 'non-empty-string'),
        ], MethodSource::Declared, false, false);

        $this->assertSame('incrementEach(array $columns, array $extra = [], &$value, string ...$rest): int', SignatureFormatter::signature($method));
        $this->assertSame(['@param array<string, int> $columns', '@param non-empty-string ...$rest'], SignatureFormatter::docblockLines($method));
    }

    public function test_formats_docblock_methods_with_docblock_types(): void
    {
        $method = new MethodDeclaration('make', Visibility::Public, true, null, '$this', [
            new ParameterDeclaration(0, 'name', null, 'null', false, false, 'string|null'),
        ], MethodSource::Docblock, false, false);

        $this->assertSame('static make(string|null $name = null): $this', SignatureFormatter::signature($method));
        $this->assertSame([], SignatureFormatter::docblockLines($method));
    }
}
