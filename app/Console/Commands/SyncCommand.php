<?php

namespace App\Console\Commands;

use App\Application\Releases\ReleaseSynchronizer;
use App\Jobs\FetchReleaseSource;
use App\Jobs\ParseReleaseSource;
use App\Jobs\RecordReleaseChanges;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

#[Signature('since:sync')]
#[Description('GitHub のタグから対象のリリースを登録し、未解析リリースの取得・解析と変更履歴の記録をキューに入れる')]
class SyncCommand extends Command
{
    public function handle(ReleaseSynchronizer $synchronizer): int
    {
        $result = $synchronizer->sync();

        $this->components->info("Registered {$result->registered} new release(s).");

        if ($result->unparsed === []) {
            // 前回記録できなかった変更履歴（記録の失敗など）が残っていれば、ここで記録し直す。
            RecordReleaseChanges::dispatch();
            $this->components->info('No unparsed releases. Queued change recording.');

            return self::SUCCESS;
        }

        // リリースごとに「取得 → 解析 → 変更履歴の記録」のチェーンにする。1 リリースの失敗で他のリリースを止めない。
        // 記録はパッケージ全体を見て、直前のリリースも解析済みのものだけを記録するので、
        // 解析が終わる順番に関係なく、最後に動いた記録ジョブがすべてを記録する。
        $jobs = array_map(
            static fn ($release) => [
                new FetchReleaseSource($release->id),
                new ParseReleaseSource($release->id),
                new RecordReleaseChanges,
            ],
            $result->unparsed,
        );

        $batch = Bus::batch($jobs)
            ->name('since:sync')
            ->allowFailures()
            ->dispatch();

        $this->components->info(sprintf('Queued fetch, parse and change recording jobs for %d release(s) in batch %s.', count($jobs), $batch->id));

        return self::SUCCESS;
    }
}
