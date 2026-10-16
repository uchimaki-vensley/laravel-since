<?php

namespace App\Application\Site\Pages;

/**
 * トップ（検索）ページ。
 */
final readonly class IndexPage
{
    /**
     * @param  list<ReleaseLink>  $releases  新しいバージョンから順
     */
    public function __construct(
        public ?string $latestVersion,
        public ?string $oldestVersion,
        public int $types,
        public int $members,
        public array $releases,
    ) {}
}
