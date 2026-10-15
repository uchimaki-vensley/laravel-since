<?php

namespace App\Jobs;

use App\Application\Releases\ReleaseSourceParser;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\SkipIfBatchCancelled;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * 展開済みの 1 リリースのソースを構文解析し、公開 API を保存する。
 */
class ParseReleaseSource implements ShouldQueue
{
    use Batchable, Queueable;

    /** 解析は入力が同じなら結果も同じなので、例外で失敗したら再試行しない。 */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $releaseId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new SkipIfBatchCancelled,
            (new WithoutOverlapping("release-parse:{$this->releaseId}"))
                ->dontRelease()
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(ReleaseSourceParser $parser): void
    {
        $parser->parse($this->releaseId);
    }
}
