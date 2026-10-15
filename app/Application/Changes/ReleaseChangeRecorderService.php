<?php

namespace App\Application\Changes;

use App\Application\Releases\ApiSnapshotRepository;
use App\Application\Releases\ReleaseSummary;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Changes\ApiDiff;
use App\Domain\Releases\PackageName;
use RuntimeException;

final readonly class ReleaseChangeRecorderService implements ReleaseChangeRecorder
{
    public function __construct(
        private ReleaseChangeRepository $changes,
        private ApiSnapshotRepository $snapshots,
        private PackageName $package,
    ) {}

    public function record(): ChangeRecordResult
    {
        $recorded = [];
        $waiting = [];
        $previous = null;
        /** @var array<int, list<TypeDeclaration>> $loaded 直前に読んだスナップショット（次のリリースの比較元になる） */
        $loaded = [];

        foreach ($this->changes->states($this->package) as $state) {
            $basis = self::basis($previous, $state);

            if ($basis === null && $state->snapshotRevision !== null) {
                $waiting[] = $state->release;
            }

            if ($basis !== null && $basis !== $state->basis) {
                // 最初のリリースは、含まれるメソッドすべてを追加として記録する（それより前は解析していないので
                // 「最初のリリース以前からある」の意味になる）。
                $before = $previous === null ? [] : $this->snapshot($previous->release, $loaded);
                $after = $this->snapshot($state->release, $loaded);
                $this->changes->replace($state->release, $basis, ApiDiff::between($before, $after));

                $loaded = [$state->release->id => $after];
                $recorded[] = $state->release;
            }

            $previous = $state;
        }

        return new ChangeRecordResult($recorded, $waiting);
    }

    /**
     * 記録する差分の根拠。直前のリリースとそれぞれのスナップショットの版が変われば値も変わる。
     * 記録できない（このリリースか直前のリリースが未解析）なら null。
     */
    private static function basis(?ReleaseChangeState $previous, ReleaseChangeState $state): ?string
    {
        if ($state->snapshotRevision === null) {
            return null;
        }

        if ($previous === null) {
            return "baseline:{$state->snapshotRevision}";
        }

        if ($previous->snapshotRevision === null) {
            return null;
        }

        return "{$previous->release->id}@{$previous->snapshotRevision}:{$state->snapshotRevision}";
    }

    /**
     * @param  array<int, list<TypeDeclaration>>  $loaded
     * @return list<TypeDeclaration>
     */
    private function snapshot(ReleaseSummary $release, array $loaded): array
    {
        return $loaded[$release->id]
            ?? $this->snapshots->load($release)
            ?? throw new RuntimeException("The release {$release->tag->name} has not been parsed.");
    }
}
