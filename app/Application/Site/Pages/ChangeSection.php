<?php

namespace App\Application\Site\Pages;

use App\Domain\Enums\SymbolChangeKind;

/**
 * リリースの変更一覧のうち、同じ種類の変化をまとめたもの。
 */
final readonly class ChangeSection
{
    /**
     * @param  int  $count  変化の件数
     * @param  list<ChangeItem>  $items  型名・メンバー名の順（一覧を省略した場合は空）
     */
    public function __construct(
        public SymbolChangeKind $kind,
        public int $count,
        public array $items,
    ) {}

    public function isOmitted(): bool
    {
        return $this->items === [] && $this->count > 0;
    }
}
