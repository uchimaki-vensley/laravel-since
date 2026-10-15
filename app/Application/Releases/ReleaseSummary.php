<?php

namespace App\Application\Releases;

use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;

/**
 * 登録済みのリリース。ユースケース間で受け渡す。
 */
final readonly class ReleaseSummary
{
    public function __construct(
        public int $id,
        public PackageName $package,
        public ReleaseTag $tag,
    ) {}
}
