<?php

namespace Tests\Unit\Domain;

use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\TypeKind;
use App\Domain\Enums\Visibility;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ApiDeclarationTest extends TestCase
{
    public function test_type_keeps_one_method_per_case_insensitive_name_preferring_declared_methods(): void
    {
        $type = new TypeDeclaration('Illuminate\Example', TypeKind::ClassType, 'src/Illuminate/Example.php', false, false, [
            self::method('where', MethodSource::Docblock, returnType: null),
            self::method('first'),
            self::method('WHERE'),
            self::method('first', MethodSource::Docblock),
            self::method('only', MethodSource::Docblock),
        ]);

        $this->assertSame(
            [['first', MethodSource::Declared], ['WHERE', MethodSource::Declared], ['only', MethodSource::Docblock]],
            array_map(fn (MethodDeclaration $method) => [$method->name, $method->source], $type->methods),
        );
    }

    public function test_signature_hash_changes_with_the_signature_but_not_with_markers(): void
    {
        $base = self::method('where');

        $this->assertSame($base->signatureHash(), self::method('where', isDeprecated: true)->signatureHash());
        $this->assertSame($base->signatureHash(), self::method('renamed')->signatureHash());
        $this->assertNotSame($base->signatureHash(), self::method('where', returnType: '?string')->signatureHash());
        $this->assertNotSame($base->signatureHash(), self::method('where', docblockType: 'non-empty-string')->signatureHash());
        $this->assertNotSame($base->signatureHash(), self::method('where', defaultValue: "'x'")->signatureHash());
        $this->assertSame(64, strlen($base->signatureHash()));
    }

    public function test_parameters_must_be_ordered_by_position(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MethodDeclaration('where', Visibility::Public, false, null, null, [
            new ParameterDeclaration(1, 'column', 'string', null, false, false, null),
        ], MethodSource::Declared, false, false);
    }

    private static function method(
        string $name,
        MethodSource $source = MethodSource::Declared,
        ?string $returnType = 'string',
        ?string $docblockType = null,
        ?string $defaultValue = null,
        bool $isDeprecated = false,
    ): MethodDeclaration {
        return new MethodDeclaration($name, Visibility::Public, false, $returnType, null, [
            new ParameterDeclaration(0, 'column', 'string', $defaultValue, false, false, $docblockType),
        ], $source, false, $isDeprecated);
    }
}
