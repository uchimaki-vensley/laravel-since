<?php

namespace App\Application\Site\Pages;

use App\Application\Site\SitePath;

/**
 * リリースへのリンクと、そのリリースの変化の件数。
 */
final readonly class ReleaseLink
{
    public function __construct(
        public string $version,
        public SitePath $path,
        public int $added,
        public int $removed,
        public int $signatureChanged,
        public int $deprecated,
    ) {}
}
