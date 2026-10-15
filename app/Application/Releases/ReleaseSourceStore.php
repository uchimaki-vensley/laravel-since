<?php

namespace App\Application\Releases;

/**
 * 展開済みのソースを置く場所。取得したソースは解析の入力としてだけ使い、実行しない。
 */
interface ReleaseSourceStore
{
    public function has(ReleaseSummary $release): bool;

    /**
     * 展開済みソースのディレクトリ。
     */
    public function path(ReleaseSummary $release): string;

    /**
     * $writeArchive に一時ファイルのパスを渡してアーカイブを書かせ、検証しながら展開して保存する。
     * 途中で失敗した場合は何も残さない。
     *
     * @param  callable(string): void  $writeArchive
     */
    public function put(ReleaseSummary $release, callable $writeArchive): void;
}
