<?php

namespace App\Console\Commands;

use App\Application\Releases\SyncReleases;
use App\Jobs\FetchReleaseSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class SyncReleasesCommand extends Command
{
    protected $signature = 'since:sync';

    protected $description = 'Fetch Laravel release tags and queue source downloads';

    public function handle(SyncReleases $sync): int
    {
        $releaseIds = $sync->execute();
        $jobs = array_map(static fn (int $releaseId): FetchReleaseSource => new FetchReleaseSource($releaseId), $releaseIds);

        if ($jobs !== []) {
            Bus::batch($jobs)
                ->name('Fetch Laravel release sources')
                ->allowFailures()
                ->dispatch();
        }

        $this->info(sprintf('Queued %d release source download(s).', count($jobs)));

        return self::SUCCESS;
    }
}
