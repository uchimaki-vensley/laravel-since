<?php

namespace App\Application\Releases;

interface ReleaseSourceFetcher
{
    /**
     * リリースのソースを取得して展開する。展開済みなら何もしない。
     */
    public function fetch(int $releaseId): void;
}
