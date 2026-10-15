<?php

namespace App\Application\Site;

/**
 * 作業中のサイトにファイルを書く。
 */
interface SiteFiles
{
    /**
     * @param  string  $path  サイトのルートからの相対パス（例: `types/Illuminate/Support/Str/index.html`）
     */
    public function write(string $path, string $contents): void;
}
