<?php

namespace App\Infrastructure\PhpSource;

use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\Visibility;
use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use UnexpectedValueException;

/**
 * phpstan/phpdoc-parser で docblock を読む。
 *
 * 型と既定値は、構文木から組み立て直さずに docblock に書かれたトークンをそのまま使う
 * （phpdoc-parser の Printer は `(Closure(): void)|null` の括弧を落とすため）。
 * 改行と連続する空白は 1 つの空白にまとめる。クラス名は解決しない。
 */
final readonly class DocblockReader
{
    private Lexer $lexer;

    private PhpDocParser $parser;

    public function __construct()
    {
        $config = new ParserConfig(['indexes' => true]);
        $constExprParser = new ConstExprParser($config);

        $this->lexer = new Lexer($config);
        $this->parser = new PhpDocParser($config, new TypeParser($config, $constExprParser), $constExprParser);
    }

    public function read(?string $docComment): Docblock
    {
        if ($docComment === null) {
            return new Docblock;
        }

        $tokens = $this->lexer->tokenize($docComment);

        try {
            $node = $this->parser->parse(new TokenIterator($tokens));
        } catch (ParserException) {
            // 書式の壊れた docblock は、型の情報がないものとして扱う。
            return new Docblock;
        }

        $paramTypes = [];

        foreach ($node->getParamTagValues() as $param) {
            $paramTypes[ltrim($param->parameterName, '$')] ??= $this->text($tokens, $param->type);
        }

        $return = $node->getReturnTagValues()[0] ?? null;

        return new Docblock(
            isInternal: $node->getTagsByName('@internal') !== [],
            isDeprecated: $node->getTagsByName('@deprecated') !== [],
            returnType: $return === null ? null : $this->text($tokens, $return->type),
            paramTypes: $paramTypes,
            methods: array_map(
                fn (MethodTagValueNode $tag) => $this->method($tokens, $tag),
                array_values($node->getMethodTagValues()),
            ),
        );
    }

    /**
     * @param  list<array{string, int, int}>  $tokens
     */
    private function method(array $tokens, MethodTagValueNode $tag): MethodDeclaration
    {
        $parameters = [];

        foreach (array_values($tag->parameters) as $position => $param) {
            $parameters[] = new ParameterDeclaration(
                position: $position,
                name: ltrim($param->parameterName, '$'),
                type: null,
                defaultValue: $param->defaultValue === null ? null : $this->text($tokens, $param->defaultValue),
                isVariadic: $param->isVariadic,
                byRef: $param->isReference,
                docblockType: $param->type === null ? null : $this->text($tokens, $param->type),
            );
        }

        return new MethodDeclaration(
            name: $tag->methodName,
            visibility: Visibility::Public,
            isStatic: $tag->isStatic,
            returnType: null,
            docblockReturnType: $tag->returnType === null ? null : $this->text($tokens, $tag->returnType),
            parameters: $parameters,
            source: MethodSource::Docblock,
            isInternal: false,
            isDeprecated: false,
        );
    }

    /**
     * ノードに対応する docblock の記述をそのまま返す。
     *
     * @param  list<array{string, int, int}>  $tokens
     */
    private function text(array $tokens, Node $node): string
    {
        $start = $node->getAttribute(Attribute::START_INDEX);
        $end = $node->getAttribute(Attribute::END_INDEX);

        if (! is_int($start) || ! is_int($end)) {
            throw new UnexpectedValueException('The docblock node has no token indexes.');
        }

        $text = '';

        for ($index = $start; $index <= $end; $index++) {
            [$value, $type] = $tokens[$index];
            $text .= $type === Lexer::TOKEN_PHPDOC_EOL ? ' ' : $value;
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
