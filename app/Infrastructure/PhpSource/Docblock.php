<?php

namespace App\Infrastructure\PhpSource;

use App\Domain\Api\MethodDeclaration;

/**
 * docblock から読み取った、公開 API に関わる情報。型は docblock に書かれたとおりの文字列で持つ。
 */
final readonly class Docblock
{
    /**
     * @param  array<string, string>  $paramTypes  引数名（`$` なし）ごとの @param の型
     * @param  list<MethodDeclaration>  $methods  @method タグで宣言されたメソッド
     */
    public function __construct(
        public bool $isInternal = false,
        public bool $isDeprecated = false,
        public ?string $returnType = null,
        public array $paramTypes = [],
        public array $methods = [],
    ) {}
}
