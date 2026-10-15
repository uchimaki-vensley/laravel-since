<?php

namespace App\Jobs;

use App\Application\Releases\ReleaseSourceFetcher;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * 1 リリースのソースを取得して展開する。展開済みのリリースは何もしない。
 */
class FetchReleaseSource implements ShouldQueue
{
    use Batchable, Queueable;

    /** レート制限や重複実行で戻された回数は数えず、例外による失敗だけを数える。 */
    public int $tries = 0;

    public int $maxExceptions = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public int $timeout = 300;

    public function __construct(public readonly int $releaseId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new SkipIfBatchCancelled,
            new RateLimited('github'),
            (new WithoutOverlapping("release-source:{$this->releaseId}"))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(ReleaseSourceFetcher $fetcher): void
    {
        $fetcher->fetch($this->releaseId);
    }
}
