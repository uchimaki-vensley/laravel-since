<?php

namespace App\Application\Releases;

interface ReleaseSynchronizer
{
    /**
     * タグ一覧から対象範囲のリリースを登録し、未解析のリリースを返す。
     */
    public function sync(): SyncResult;
}
