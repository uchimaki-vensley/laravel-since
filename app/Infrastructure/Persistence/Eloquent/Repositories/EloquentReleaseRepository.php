<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Releases\ReleaseRepository;
use App\Application\Releases\ReleaseSummary;
use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use UnexpectedValueException;

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
            ->map(self::toSummary(...))
            ->all();

        usort($releases, static fn (ReleaseSummary $a, ReleaseSummary $b) => $a->tag->version->compare($b->tag->version));

        return $releases;
    }

    public function find(int $id): ?ReleaseSummary
    {
        $release = Release::query()->find($id);

        return $release === null ? null : self::toSummary($release);
    }

    private static function toSummary(Release $release): ReleaseSummary
    {
        $tag = ReleaseTag::tryParse($release->tag);

        if ($tag === null || (string) $tag->version !== $release->version) {
            throw new UnexpectedValueException("Release {$release->id} has an invalid tag: {$release->tag}");
        }

        return new ReleaseSummary($release->id, new PackageName($release->package), $tag);
    }
}
