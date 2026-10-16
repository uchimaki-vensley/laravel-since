<?php

namespace App\Application\Changes;

use App\Application\Releases\ReleaseSummary;

/**
 * 差分の記録に必要なリリースの状態。
 */
final readonly class ReleaseChangeState
{
    /**
     * @param  ?int  $snapshotRevision  未解析なら null
     * @param  ?string  $basis  記録済みの差分がどのスナップショット同士の比較か。未記録なら null
     */
    public function __construct(
        public ReleaseSummary $release,
        public ?int $snapshotRevision,
        public ?string $basis,
    ) {}
}
