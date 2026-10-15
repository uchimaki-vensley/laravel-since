<?php

namespace App\Application\Releases;

use App\Domain\Api\TypeDeclaration;

interface ApiSnapshotRepository
{
    /**
     * リリースの公開 API を保存し、リリースを解析済みにしてスナップショットの版を進める。
     * 既存のスナップショットは置き換える。途中で失敗した場合は何も変えない。
     *
     * @param  list<TypeDeclaration>  $types
     */
    public function replace(ReleaseSummary $release, array $types): void;

    /**
     * 保存済みの公開 API を返す。未解析なら null。
     *
     * @return ?list<TypeDeclaration>
     */
    public function load(ReleaseSummary $release): ?array;
}
