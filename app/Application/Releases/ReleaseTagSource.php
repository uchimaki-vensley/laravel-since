<?php

namespace App\Application\Releases;

use App\Domain\Releases\PackageName;

interface ReleaseTagSource
{
    /**
     * リポジトリのタグ名をすべて返す。
     *
     * @return list<string>
     */
    public function tagNames(PackageName $package): array;
}
