<?php

namespace App\Application\Site;

/**
 * サイトの出力先。
 */
interface SiteOutput
{
    /**
     * 空の作業場所に静的ファイルを置いてから $build にページを書かせ、書き終えたら出力先を丸ごと置き換える。
     * 途中で失敗した場合は出力先を変えない。
     *
     * @param  callable(SiteFiles): void  $build
     */
    public function replace(callable $build): void;
}
