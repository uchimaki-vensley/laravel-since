<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Releases\ReleaseRepository;
use App\Application\Releases\ReleaseSummary;
use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;
use App\Domain\Releases\Version;
use App\Infrastructure\Persistence\Eloquent\Models\Release;

final class EloquentReleaseRepository implements ReleaseRepository
{
    public function registerMissing(PackageName $package, array $tags): int
    {
        $rows = array_map(static fn (ReleaseTag $tag) => [
            'package' => $package->value,
            'version' => (string) $tag->version,
            'tag' => $tag->name,
        ], $tags);

        $registered = 0;

        // SQLite のバインド変数の上限を超えないよう分けて入れる。
        foreach (array_chunk($rows, 500) as $chunk) {
            $registered += Release::query()->insertOrIgnore($chunk);
        }

        return $registered;
    }

    public function unparsed(PackageName $package): array
    {
        $releases = Release::query()
            ->where('package', $package->value)
            ->whereNull('parsed_at')
            ->get()
            ->map(ReleaseSummaryMapper::toSummary(...))
            ->all();

        usort($releases, static fn (ReleaseSummary $a, ReleaseSummary $b) => $a->tag->version->compare($b->tag->version));

        return $releases;
    }

    public function find(int $id): ?ReleaseSummary
    {
        $release = Release::query()->find($id);

        return $release === null ? null : ReleaseSummaryMapper::toSummary($release);
    }

    public function findByVersion(PackageName $package, Version $version): ?ReleaseSummary
    {
        $release = Release::query()
            ->where('package', $package->value)
            ->where('version', (string) $version)
            ->first();

        return $release === null ? null : ReleaseSummaryMapper::toSummary($release);
    }
}
