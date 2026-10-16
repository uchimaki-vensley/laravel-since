<?php

namespace App\Application\Site;

use App\Application\Releases\ReleaseSummary;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Releases\PackageName;

/**
 * サイトの出力に使うデータの読み出し口。
 */
interface SiteCatalog
{
    /**
     * 解析し、変更履歴も記録したリリースを古いバージョンから順に返す。
     *
     * @return list<ReleaseSummary>
     */
    public function parsedReleases(PackageName $package): array;

    /**
     * リリースの公開 API のスナップショット。
     *
     * @return list<TypeDeclaration>
     */
    public function types(ReleaseSummary $release): array;

    /**
     * パッケージの全リリースの変更履歴（順序は問わない）。
     *
     * @return list<SymbolChangeEntry>
     */
    public function changes(PackageName $package): array;
}
