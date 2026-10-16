<?php

namespace App\Application\Changes;

interface ReleaseChangeRecorder
{
    /**
     * 解析済みのリリースを直前のリリースと比べ、まだ記録していない（または古くなった）変更履歴を記録する。
     * 何度実行しても同じ結果になる。
     */
    public function record(): ChangeRecordResult;
}
