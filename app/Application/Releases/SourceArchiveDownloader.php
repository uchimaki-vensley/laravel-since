<?php

namespace App\Application\Releases;

use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;

interface SourceArchiveDownloader
{
    /**
     * タグのソースアーカイブ（tar.gz）を $destination に保存する。
     */
    public function download(PackageName $package, ReleaseTag $tag, string $destination): void;
}
