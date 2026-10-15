<?php

namespace App\Jobs;

use App\Application\Changes\ReleaseChangeRecorder;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * 解析済みのリリースを直前のリリースと比べ、まだ記録していない変更履歴を記録する。
 * 対象はパッケージ全体なので、何度動かしても同じ結果になる。
 */
class RecordReleaseChanges implements ShouldQueue
{
    use Batchable, Queueable;

    /** 実行中の記録ジョブと重ならないよう戻された回数は数えず、例外による失敗だけを数える。 */
    public int $tries = 0;

    public int $maxExceptions = 1;

    public int $timeout = 1800;

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // 実行中の記録が読み終えた後に解析が終わったリリースもあるので、重なったら捨てずに後で動かす。
        return [
            new SkipIfBatchCancelled,
            (new WithoutOverlapping('release-changes'))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(ReleaseChangeRecorder $recorder): void
    {
        $recorder->record();
    }
}
