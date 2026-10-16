<?php

namespace App\Application\Site\Pages;

/**
 * リリースへのリンクと、そのリリースの変化の件数。
 */
final readonly class ReleaseLink
{
    public function __construct(
        public string $version,
        public string $url,
        public int $added,
        public int $removed,
        public int $signatureChanged,
        public int $deprecated,
    ) {}
}
