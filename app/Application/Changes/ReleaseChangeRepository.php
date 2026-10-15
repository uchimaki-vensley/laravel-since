<?php

namespace App\Application\Changes;

use App\Application\Releases\ReleaseSummary;
use App\Domain\Changes\MethodChange;
use App\Domain\Changes\SymbolName;
use App\Domain\Releases\PackageName;

interface ReleaseChangeRepository
{
    /**
     * パッケージの登録済みリリースを古いバージョンから順に返す。
     *
     * @return list<ReleaseChangeState>
     */
    public function states(PackageName $package): array;

    /**
     * $symbols と変化のあったメソッドのシンボルを（なければ）作り、リリースの変更履歴を $changes で置き換える。
     * 途中で失敗した場合は何も変えない。
     *
     * @param  list<SymbolName>  $symbols
     * @param  list<MethodChange>  $changes
     */
    public function replace(ReleaseSummary $release, string $basis, array $symbols, array $changes): void;
}
