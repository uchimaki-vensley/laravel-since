<?php

namespace Tests\Unit\Infrastructure;

use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\TypeKind;
use App\Infrastructure\PhpSource\DocblockReader;
use App\Infrastructure\PhpSource\PhpSourceParser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PhpSourceParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/PhpSource';

    /** @var array<string, TypeDeclaration> */
    private array $types;

    protected function setUp(): void
    {
        parent::setUp();

        $types = (new PhpSourceParser(new DocblockReader))->parse(self::FIXTURE);
        $this->types = array_column(array_map(fn (TypeDeclaration $type) => [$type->fqcn, $type], $types), 1, 0);
    }

    public function test_extracts_named_types_under_src_illuminate_without_executing_them(): void
    {
        // Widget.php は末尾で例外を投げるので、読み込まれていればここに来ない。
        $this->assertSame([
            'Illuminate\Fixture\Concerns\HasStatus' => [TypeKind::TraitType, 'src/Illuminate/Fixture/Concerns/HasStatus.php'],
            'Illuminate\Fixture\Conditional' => [TypeKind::ClassType, 'src/Illuminate/Fixture/Conditional.php'],
            'Illuminate\Fixture\Contracts\Renderable' => [TypeKind::InterfaceType, 'src/Illuminate/Fixture/Contracts/Renderable.php'],
            'Illuminate\Fixture\Status' => [TypeKind::EnumType, 'src/Illuminate/Fixture/Status.php'],
            'Illuminate\Fixture\Widget' => [TypeKind::ClassType, 'src/Illuminate/Fixture/Widget.php'],
        ], array_map(fn (TypeDeclaration $type) => [$type->kind, $type->filePath], $this->types));
        $this->assertFalse(class_exists('Illuminate\Fixture\Widget', false));
    }

    public function test_keeps_only_public_methods_and_skips_anonymous_classes(): void
    {
        $this->assertSame(['status'], $this->methodNames('Illuminate\Fixture\Concerns\HasStatus'));
        $this->assertSame(['label', 'fromLabel'], $this->methodNames('Illuminate\Fixture\Status'));
        $this->assertSame(['render'], $this->methodNames('Illuminate\Fixture\Contracts\Renderable'));
        $this->assertSame([
            '__construct', 'configure', 'intersect', 'render', 'internalHelper', 'legacy',
            'attributeDeprecated', 'brokenDocblock', 'anonymous', 'make', 'names', 'forward', 'hidden',
        ], $this->methodNames('Illuminate\Fixture\Widget'));
    }

    public function test_records_native_and_docblock_types_of_parameters(): void
    {
        $configure = $this->method('Illuminate\Fixture\Widget', 'configure');

        $this->assertSame(MethodSource::Declared, $configure->source);
        $this->assertFalse($configure->isStatic);
        $this->assertSame('static', $configure->returnType);
        $this->assertSame('$this', $configure->docblockReturnType);
        $this->assertEquals([
            new ParameterDeclaration(0, 'attributes', 'array', '[]', false, false, 'array<string, int|string>'),
            new ParameterDeclaration(1, 'callback', '?Closure', 'null', false, false, '(Closure(static): void)|null'),
            new ParameterDeclaration(2, 'count', 'int', '0', false, true, null),
            new ParameterDeclaration(3, 'fallback', 'Illuminate\Fixture\Contracts\Renderable|string|null', 'self::DEFAULT', false, false, null),
            new ParameterDeclaration(4, 'tags', 'string', null, true, false, 'non-empty-string'),
        ], $configure->parameters);
    }

    public function test_prints_composite_native_types_and_constant_defaults(): void
    {
        $this->assertEquals([
            new ParameterDeclaration(0, 'value', '(Countable&ArrayAccess)|null', null, false, false, null),
            new ParameterDeclaration(1, 'untyped', null, 'PHP_EOL', false, false, null),
        ], $this->method('Illuminate\Fixture\Widget', 'intersect')->parameters);

        $this->assertSame('?Illuminate\Fixture\Contracts\Renderable', $this->method('Illuminate\Fixture\Widget', 'legacy')->returnType);
        $this->assertTrue($this->method('Illuminate\Fixture\Widget', 'legacy')->isStatic);
        $this->assertSame('Illuminate\Fixture\Status', $this->method('Illuminate\Fixture\Concerns\HasStatus', 'status')->returnType);
        $this->assertSame('non-empty-string', $this->method('Illuminate\Fixture\Contracts\Renderable', 'render')->docblockReturnType);
        $this->assertNull($this->method('Illuminate\Fixture\Widget', '__construct')->returnType);
    }

    public function test_records_internal_and_deprecated_markers(): void
    {
        $this->assertTrue($this->method('Illuminate\Fixture\Widget', 'internalHelper')->isInternal);
        $this->assertTrue($this->method('Illuminate\Fixture\Widget', 'legacy')->isDeprecated);
        $this->assertTrue($this->method('Illuminate\Fixture\Widget', 'attributeDeprecated')->isDeprecated);
        $this->assertFalse($this->method('Illuminate\Fixture\Widget', 'render')->isInternal);
        $this->assertFalse($this->method('Illuminate\Fixture\Widget', 'render')->isDeprecated);
        $this->assertTrue($this->types['Illuminate\Fixture\Conditional']->isInternal);
        $this->assertFalse($this->types['Illuminate\Fixture\Widget']->isInternal);
    }

    public function test_records_docblock_method_tags_without_resolving_them(): void
    {
        $make = $this->method('Illuminate\Fixture\Widget', 'make');

        $this->assertSame(MethodSource::Docblock, $make->source);
        $this->assertTrue($make->isStatic);
        $this->assertNull($make->returnType);
        $this->assertSame('\Illuminate\Fixture\Widget', $make->docblockReturnType);
        $this->assertEquals([
            new ParameterDeclaration(0, 'name', null, 'null', false, false, 'string|null'),
            new ParameterDeclaration(1, 'options', null, null, true, false, 'array'),
        ], $make->parameters);

        $this->assertSame('array<int, string>', $this->method('Illuminate\Fixture\Widget', 'names')->docblockReturnType);
        $this->assertEquals([
            new ParameterDeclaration(0, 'count', null, null, false, true, 'int'),
            new ParameterDeclaration(1, 'untyped', null, 'self::DEFAULT', false, false, null),
        ], $this->method('Illuminate\Fixture\Widget', 'forward')->parameters);
    }

    public function test_prefers_the_declared_method_over_a_docblock_method_with_the_same_name(): void
    {
        $render = $this->method('Illuminate\Fixture\Widget', 'render');

        $this->assertSame(MethodSource::Declared, $render->source);
        $this->assertSame('string', $render->returnType);
        // 大文字小文字だけが違う @method FORWARD() は forward と同じメソッドとして 1 つにまとめる。
        $this->assertNotContains('FORWARD', $this->methodNames('Illuminate\Fixture\Widget'));
    }

    public function test_keeps_the_docblock_method_when_the_declared_method_with_the_same_name_is_not_public(): void
    {
        // protected hidden() は外から呼ぶと __call に転送されるので、@method int hidden() が公開 API になる。
        $hidden = $this->method('Illuminate\Fixture\Widget', 'hidden');

        $this->assertSame(MethodSource::Docblock, $hidden->source);
        $this->assertSame('int', $hidden->docblockReturnType);
    }

    public function test_merges_the_route_registrar_metadata_method_of_laravel_13_36_into_the_declared_method(): void
    {
        $types = (new PhpSourceParser(new DocblockReader))->parse(__DIR__.'/../../Fixtures/LaravelExcerpts');

        $this->assertCount(1, $types);
        $this->assertSame('Illuminate\Routing\RouteRegistrar', $types[0]->fqcn);
        $this->assertSame(
            [
                ['attribute', MethodSource::Declared],
                ['metadata', MethodSource::Declared],
                ['__call', MethodSource::Declared],
                ['get', MethodSource::Docblock],
                ['as', MethodSource::Docblock],
                ['middleware', MethodSource::Docblock],
            ],
            array_map(fn (MethodDeclaration $method) => [$method->name, $method->source], $types[0]->methods),
        );

        $metadata = $types[0]->methods[1];
        $this->assertNull($metadata->returnType);
        $this->assertSame('$this', $metadata->docblockReturnType);
        $this->assertEquals([new ParameterDeclaration(0, 'metadata', 'array', null, false, false, 'array')], $metadata->parameters);
        $this->assertFalse(class_exists('Illuminate\Routing\RouteRegistrar', false));
    }

    public function test_ignores_a_docblock_it_cannot_parse(): void
    {
        $method = $this->method('Illuminate\Fixture\Widget', 'brokenDocblock');

        $this->assertSame('array', $method->returnType);
        $this->assertNull($method->docblockReturnType);
    }

    public function test_uses_the_first_declaration_of_a_conditionally_declared_type(): void
    {
        $this->assertSame(['first'], $this->methodNames('Illuminate\Fixture\Conditional'));
    }

    public function test_fails_when_the_api_directory_is_missing(): void
    {
        $this->expectException(RuntimeException::class);

        (new PhpSourceParser(new DocblockReader))->parse(self::FIXTURE.'/tests');
    }

    public function test_reports_the_file_that_cannot_be_parsed(): void
    {
        $root = sys_get_temp_dir().'/since-parser-'.bin2hex(random_bytes(8));
        mkdir("{$root}/src/Illuminate", 0755, true);
        file_put_contents("{$root}/src/Illuminate/Broken.php", '<?php class {');

        try {
            (new PhpSourceParser(new DocblockReader))->parse($root);
            $this->fail('The syntax error was not reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Cannot parse src/Illuminate/Broken.php:', $exception->getMessage());
        } finally {
            unlink("{$root}/src/Illuminate/Broken.php");
            rmdir("{$root}/src/Illuminate");
            rmdir("{$root}/src");
            rmdir($root);
        }
    }

    /**
     * @return list<string>
     */
    private function methodNames(string $fqcn): array
    {
        return array_map(fn (MethodDeclaration $method) => $method->name, $this->types[$fqcn]->methods);
    }

    private function method(string $fqcn, string $name): MethodDeclaration
    {
        foreach ($this->types[$fqcn]->methods as $method) {
            if ($method->name === $name) {
                return $method;
            }
        }

        $this->fail("{$fqcn}::{$name} was not extracted.");
    }
}
