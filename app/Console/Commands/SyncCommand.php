<?php

namespace App\Console\Commands;

use App\Application\Releases\ReleaseSynchronizer;
use App\Jobs\FetchReleaseSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

#[Signature('since:sync')]
#[Description('GitHub のタグから対象のリリースを登録し、未解析リリースのソース取得をキューに入れる')]
class SyncCommand extends Command
{
    public function handle(ReleaseSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync();

        $this->components->info("Registered {$result->registered} new release(s).");

        if ($result->unparsed === []) {
            $this->components->info('No unparsed releases.');

            return self::SUCCESS;
        }

        $jobs = array_map(
            static fn ($release) => new FetchReleaseSource($release->id),
            $result->unparsed,
        );

        // 1 リリースの失敗で他のリリースを止めない。
        $batch = Bus::batch($jobs)
            ->name('since:sync')
            ->allowFailures()
            ->dispatch();

        $this->components->info(sprintf('Queued %d source fetch job(s) in batch %s.', count($jobs), $batch->id));

        return self::SUCCESS;
    }
}
