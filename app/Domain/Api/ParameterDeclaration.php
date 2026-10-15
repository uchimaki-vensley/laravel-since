<?php

namespace App\Domain\Api;

/**
 * メソッドの引数。型と既定値はソースに書かれた形の文字列で持つ。
 */
final readonly class ParameterDeclaration
{
    public function __construct(
        public int $position,
        public string $name,
        public ?string $type,
        public ?string $defaultValue,
        public bool $isVariadic,
        public bool $byRef,
        public ?string $docblockType,
    ) {}
}
