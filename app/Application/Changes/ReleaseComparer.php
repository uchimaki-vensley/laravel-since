<?php

namespace App\Application\Changes;

interface ReleaseComparer
{
    /**
     * 解析済みの 2 つのリリースの公開 API を直接比べる（間のリリースは見ない）。
     *
     * @param  string  $from  比較元のバージョン（`13.2.0` または `v13.2.0`）
     * @param  string  $to  比較先のバージョン
     *
     * @throws ComparisonUnavailable バージョンの形式が不正、未登録、または未解析の場合
     */
    public function compare(string $from, string $to): ReleaseComparison;
}
