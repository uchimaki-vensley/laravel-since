<?php

namespace App\Application\Site;

interface SiteBuilder
{
    /**
     * 解析済みのリリースと変更履歴から静的サイトを作り、出力先を置き換える。
     */
    public function build(): SiteBuildResult;
}
