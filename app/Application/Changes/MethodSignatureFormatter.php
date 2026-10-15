<?php

namespace App\Application\Changes;

use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Enums\MethodSource;

/**
 * メソッドのシグネチャを 1 行の文字列にする（差分の表示と変更履歴の保存に使う）。
 *
 * - 宣言メソッド: ネイティブの型で書き、docblock の型があれば末尾の [] にまとめる。
 *   例: `incrementEach(array $columns, array $extra = []): int [@param array<string, mixed> $extra]`
 * - docblock の @method: docblock の型で書き、先頭に `@method` を付ける。
 *   例: `@method static where(string $column): static`
 */
final class MethodSignatureFormatter
{
    public static function format(MethodDeclaration $method): string
    {
        $docblock = $method->source === MethodSource::Docblock;
        $parameters = array_map(
            static fn (ParameterDeclaration $parameter) => self::parameter($parameter, $docblock ? $parameter->docblockType : $parameter->type),
            $method->parameters,
        );

        $text = ($docblock ? '@method ' : '').($method->isStatic ? 'static ' : '').$method->name.'('.implode(', ', $parameters).')';
        $returnType = $docblock ? $method->docblockReturnType : $method->returnType;

        if ($returnType !== null) {
            $text .= ': '.$returnType;
        }

        if ($docblock) {
            return $text;
        }

        $tags = [];

        foreach ($method->parameters as $parameter) {
            if ($parameter->docblockType !== null) {
                $tags[] = '@param '.self::parameter($parameter, $parameter->docblockType, withDefault: false);
            }
        }

        if ($method->docblockReturnType !== null) {
            $tags[] = '@return '.$method->docblockReturnType;
        }

        return $tags === [] ? $text : $text.' ['.implode(', ', $tags).']';
    }

    private static function parameter(ParameterDeclaration $parameter, ?string $type, bool $withDefault = true): string
    {
        $text = ($type !== null ? $type.' ' : '')
            .($parameter->byRef ? '&' : '')
            .($parameter->isVariadic ? '...' : '')
            .'$'.$parameter->name;

        return $withDefault && $parameter->defaultValue !== null ? "{$text} = {$parameter->defaultValue}" : $text;
    }
}
