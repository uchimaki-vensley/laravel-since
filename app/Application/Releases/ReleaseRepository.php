<?php

namespace App\Application\Releases;

use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;
use App\Domain\Releases\Version;

interface ReleaseRepository
{
    /**
     * 未登録のリリースだけを追加し、追加した件数を返す。
     *
     * @param  list<ReleaseTag>  $tags
     */
    public function registerMissing(PackageName $package, array $tags): int;

    /**
     * 未解析のリリースを古いバージョンから順に返す。
     *
     * @return list<ReleaseSummary>
     */
    public function unparsed(PackageName $package): array;

    public function find(int $id): ?ReleaseSummary;

    public function findByVersion(PackageName $package, Version $version): ?ReleaseSummary;
}
