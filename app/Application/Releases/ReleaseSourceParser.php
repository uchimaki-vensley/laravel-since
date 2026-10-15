<?php

namespace App\Application\Releases;

interface ReleaseSourceParser
{
    /**
     * 展開済みのソースから公開 API を抽出して保存する。
     */
    public function parse(int $releaseId): void;
}
