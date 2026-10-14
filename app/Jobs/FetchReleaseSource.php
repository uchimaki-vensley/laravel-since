<?php

namespace App\Jobs;

use App\Application\Releases\GitHubReleaseSource;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class FetchReleaseSource implements ShouldQueue
{
    use Batchable, Dispatchable, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 360;

    public function __construct(public readonly int $releaseId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('release-source-'.$this->releaseId))->releaseAfter(30)->expireAfter(3600)];
    }

    public function handle(GitHubReleaseSource $source): void
    {
        $source->downloadAndExtract($this->releaseId);
    }
}
