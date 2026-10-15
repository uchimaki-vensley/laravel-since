<?php

namespace App\Application\Releases;

use App\Domain\Api\TypeDeclaration;

interface ApiSnapshotRepository
{
    /**
     * リリースの公開 API を保存し、リリースを解析済みにする。
     * 既存のスナップショットは置き換える。途中で失敗した場合は何も変えない。
     *
     * @param  list<TypeDeclaration>  $types
     */
    public function replace(ReleaseSummary $release, array $types): void;
}
