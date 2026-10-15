<?php

namespace App\Infrastructure\PhpSource;

use App\Application\Releases\SourceParser;
use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\TypeKind;
use App\Domain\Enums\Visibility;
use FilesystemIterator;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\UnionType;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use UnexpectedValueException;

/**
 * nikic/php-parser でソースを構文木にして公開 API を抽出する。
 * ファイルは文字列として読むだけで、require / include / eval / オートロードはしない（docs/security.md）。
 */
final readonly class PhpSourceParser implements SourceParser
{
    /** 公開 API として扱うディレクトリ（展開済みソースのルートからの相対パス） */
    private const string API_DIRECTORY = 'src/Illuminate';

    private Parser $parser;

    private Standard $printer;

    public function __construct(private DocblockReader $docblocks)
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->printer = new Standard(['shortArraySyntax' => true]);
    }

    public function parse(string $directory): array
    {
        $types = [];

        foreach ($this->files($directory) as $relativePath => $file) {
            foreach ($this->classLikes($file, $relativePath) as $classLike) {
                $type = $this->type($classLike, $relativePath);
                // 同じ型が複数のファイルにある場合は、パス順で最初のものを使う。
                $types[strtolower($type->fqcn)] ??= $type;
            }
        }

        return array_values($types);
    }

    /**
     * API_DIRECTORY 配下の .php ファイルをパス順に返す。シンボリックリンクはたどらない。
     *
     * @return array<string, string> ソースのルートからの相対パス => 絶対パス
     */
    private function files(string $directory): array
    {
        $root = $directory.'/'.self::API_DIRECTORY;

        if (! is_dir($root) || is_link($root)) {
            throw new RuntimeException("The source has no {$root} directory.");
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                $files[substr($path, strlen($directory) + 1)] = $path;
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<ClassLike>
     */
    private function classLikes(string $file, string $relativePath): array
    {
        $code = file_get_contents($file);

        if ($code === false) {
            throw new RuntimeException("Cannot read {$relativePath}.");
        }

        try {
            $statements = $this->parser->parse($code) ?? [];
        } catch (Error $error) {
            throw new RuntimeException("Cannot parse {$relativePath}: {$error->getMessage()}", previous: $error);
        }

        $traverser = new NodeTraverser(new NameResolver);
        $statements = $traverser->traverse($statements);

        // 無名クラスは名前がない（namespacedName が null）ので対象外にする。
        return array_values(array_filter(
            (new NodeFinder)->findInstanceOf($statements, ClassLike::class),
            static fn (ClassLike $classLike) => $classLike->namespacedName !== null,
        ));
    }

    private function type(ClassLike $classLike, string $relativePath): TypeDeclaration
    {
        $docblock = $this->docblocks->read($classLike->getDocComment()?->getText());
        $methods = [];

        foreach ($classLike->getMethods() as $method) {
            if ($method->isPublic()) {
                $methods[] = $this->declaredMethod($method);
            }
        }

        foreach ($docblock->methods as $method) {
            $methods[] = $method;
        }

        return new TypeDeclaration(
            fqcn: (string) $classLike->namespacedName,
            kind: match (true) {
                $classLike instanceof Class_ => TypeKind::ClassType,
                $classLike instanceof Interface_ => TypeKind::InterfaceType,
                $classLike instanceof Trait_ => TypeKind::TraitType,
                $classLike instanceof Enum_ => TypeKind::EnumType,
                default => throw new UnexpectedValueException('Unknown type declaration: '.$classLike::class),
            },
            filePath: $relativePath,
            isInternal: $docblock->isInternal,
            isDeprecated: $docblock->isDeprecated || $this->hasDeprecatedAttribute($classLike),
            methods: $methods,
        );
    }

    private function declaredMethod(ClassMethod $method): MethodDeclaration
    {
        $docblock = $this->docblocks->read($method->getDocComment()?->getText());
        $parameters = [];

        foreach ($method->params as $position => $param) {
            if (! $param->var instanceof Variable || ! is_string($param->var->name)) {
                throw new UnexpectedValueException("Unexpected parameter in {$method->name}.");
            }

            $name = $param->var->name;
            $parameters[] = new ParameterDeclaration(
                position: $position,
                name: $name,
                type: $this->nativeType($param->type),
                defaultValue: $param->default === null ? null : $this->printer->prettyPrintExpr($param->default),
                isVariadic: $param->variadic,
                byRef: $param->byRef,
                docblockType: $docblock->paramTypes[$name] ?? null,
            );
        }

        return new MethodDeclaration(
            name: $method->name->toString(),
            visibility: Visibility::Public,
            isStatic: $method->isStatic(),
            returnType: $this->nativeType($method->returnType),
            docblockReturnType: $docblock->returnType,
            parameters: $parameters,
            source: MethodSource::Declared,
            isInternal: $docblock->isInternal,
            isDeprecated: $docblock->isDeprecated || $this->hasDeprecatedAttribute($method),
        );
    }

    /**
     * ネイティブ型を文字列にする。クラス名は名前解決した完全修飾名（先頭の `\` なし）にする。
     */
    private function nativeType(?Node $type): ?string
    {
        return match (true) {
            $type === null => null,
            $type instanceof Identifier, $type instanceof Name => $type->toString(),
            $type instanceof NullableType => '?'.$this->nativeType($type->type),
            $type instanceof UnionType => implode('|', array_map(
                fn (Node $member) => $member instanceof IntersectionType
                    ? '('.$this->nativeType($member).')'
                    : (string) $this->nativeType($member),
                $type->types,
            )),
            $type instanceof IntersectionType => implode('&', array_map(
                fn (Node $member) => (string) $this->nativeType($member),
                $type->types,
            )),
            default => throw new UnexpectedValueException('Unknown type node: '.$type::class),
        };
    }

    /**
     * PHP 8.4 の #[\Deprecated] 属性があるか。
     */
    private function hasDeprecatedAttribute(ClassLike|ClassMethod $node): bool
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($attribute->name->toString() === 'Deprecated') {
                    return true;
                }
            }
        }

        return false;
    }
}
