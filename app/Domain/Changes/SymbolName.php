<?php

namespace App\Domain\Changes;

use Stringable;

/**
 * リリースをまたいで同じメソッドを指す名前（型の完全修飾名とメソッド名）。
 */
final readonly class SymbolName implements Stringable
{
    public function __construct(
        public string $fqcn,
        public string $member,
    ) {}

    /**
     * 同一性の判定に使うキー。PHP のクラス名とメソッド名は大文字小文字を区別しないので小文字で比べる。
     */
    public function key(): string
    {
        return strtolower($this->fqcn).'::'.strtolower($this->member);
    }

    public function __toString(): string
    {
        return "{$this->fqcn}::{$this->member}";
    }
}
