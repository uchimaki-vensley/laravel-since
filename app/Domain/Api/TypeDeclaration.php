<?php

namespace App\Domain\Api;

use App\Domain\Enums\MethodSource;
use App\Domain\Enums\TypeKind;

/**
 * 公開 API としての型（クラス・インターフェース・トレイト・enum）と、その public メソッド。
 */
final readonly class TypeDeclaration
{
    /** @var list<MethodDeclaration> */
    public array $methods;

    /**
     * PHP のメソッド名は大文字小文字を区別しないので、同じ名前は 1 つにまとめる。
     * docblock の @method と同名の宣言メソッドがある場合は宣言メソッドを残す。PHP は宣言メソッドを直接呼び、
     * __call には来ないので、@method は同じメソッドの説明にすぎない（Issue #11、docs/architecture.md）。
     *
     * @param  list<MethodDeclaration>  $methods
     */
    public function __construct(
        public string $fqcn,
        public TypeKind $kind,
        public string $filePath,
        public bool $isInternal,
        public bool $isDeprecated,
        array $methods,
    ) {
        usort($methods, static fn (MethodDeclaration $a, MethodDeclaration $b) => self::priority($a) <=> self::priority($b));

        $unique = [];

        foreach ($methods as $method) {
            $unique[strtolower($method->name)] ??= $method;
        }

        $this->methods = array_values($unique);
    }

    private static function priority(MethodDeclaration $method): int
    {
        return $method->source === MethodSource::Declared ? 0 : 1;
    }
}
