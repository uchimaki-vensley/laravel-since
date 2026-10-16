<?php

namespace App\Application\Site;

use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Enums\MethodSource;

/**
 * メソッドのシグネチャを表示用の 1 行にする。
 */
final class SignatureFormatter
{
    /**
     * 例: `static slug(string $title, string $separator = '-'): string`
     *
     * 宣言されたメソッドはネイティブの型を、`@method` は docblock の型を使う。
     */
    public static function signature(MethodDeclaration $method): string
    {
        $docblock = $method->source === MethodSource::Docblock;
        $parameters = array_map(
            static fn (ParameterDeclaration $parameter) => self::parameter($parameter, $docblock ? $parameter->docblockType : $parameter->type),
            $method->parameters,
        );
        $returnType = $docblock ? $method->docblockReturnType : $method->returnType;

        return ($method->isStatic ? 'static ' : '')
            .$method->name.'('.implode(', ', $parameters).')'
            .($returnType === null ? '' : ": {$returnType}");
    }

    /**
     * 宣言されたメソッドの docblock に書かれた型（例: `@param array<string, mixed> $attributes`）。
     *
     * @return list<string>
     */
    public static function docblockLines(MethodDeclaration $method): array
    {
        if ($method->source === MethodSource::Docblock) {
            return [];
        }

        $lines = [];

        foreach ($method->parameters as $parameter) {
            if ($parameter->docblockType !== null) {
                $lines[] = "@param {$parameter->docblockType} ".($parameter->isVariadic ? '...' : '')."\${$parameter->name}";
            }
        }

        if ($method->docblockReturnType !== null) {
            $lines[] = "@return {$method->docblockReturnType}";
        }

        return $lines;
    }

    private static function parameter(ParameterDeclaration $parameter, ?string $type): string
    {
        return ($type === null ? '' : "{$type} ")
            .($parameter->byRef ? '&' : '')
            .($parameter->isVariadic ? '...' : '')
            ."\${$parameter->name}"
            .($parameter->defaultValue === null ? '' : " = {$parameter->defaultValue}");
    }
}
