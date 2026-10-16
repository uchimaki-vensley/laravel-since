<?php

namespace App\Application\Site;

final readonly class SiteBuildResult
{
    /**
     * @param  list<string>  $skippedTypes  ページのパスにできない型名（出力しなかったもの）
     */
    public function __construct(
        public int $releases,
        public int $types,
        public int $members,
        public array $skippedTypes,
    ) {}
}
