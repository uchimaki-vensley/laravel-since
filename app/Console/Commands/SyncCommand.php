<?php

namespace App\Console\Commands;

use App\Application\Releases\ReleaseSynchronizer;
use App\Jobs\FetchReleaseSource;
use App\Jobs\ParseReleaseSource;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

#[Signature('since:sync')]
#[Description('GitHub のタグから対象のリリースを登録し、未解析リリースの取得と解析をキューに入れる')]
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

        // リリースごとに「取得 → 解析」のチェーンにする。1 リリースの失敗で他のリリースを止めない。
        $jobs = array_map(
            static fn ($release) => [new FetchReleaseSource($release->id), new ParseReleaseSource($release->id)],
            $result->unparsed,
        );

        $batch = Bus::batch($jobs)
            ->name('since:sync')
            ->allowFailures()
            ->dispatch();

        $this->components->info(sprintf('Queued fetch and parse jobs for %d release(s) in batch %s.', count($jobs), $batch->id));

        return self::SUCCESS;
    }
}
