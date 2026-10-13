<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Releases\ReleaseRepository;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use RuntimeException;

class EloquentReleaseRepository implements ReleaseRepository
{
    public function updateOrCreate(array $attributes): int
    {
        $release = Release::query()->updateOrCreate(
            ['package' => $attributes['package'], 'version' => $attributes['version']],
            ['tag' => $attributes['tag'], 'released_at' => $attributes['released_at']],
        );

        $id = filter_var($release->getKey(), FILTER_VALIDATE_INT);
        if (! is_int($id)) {
            throw new RuntimeException('Release record has an invalid ID.');
        }

        return $id;
    }

    public function unparsed(string $package): array
    {
        $rawIds = Release::query()->where('package', $package)->whereNull('parsed_at')->orderBy('version')->pluck('id')->all();
        $ids = [];
        foreach ($rawIds as $rawId) {
            $id = filter_var($rawId, FILTER_VALIDATE_INT);
            if (! is_int($id)) {
                throw new RuntimeException('Release record has an invalid ID.');
            }
            $ids[] = $id;
        }

        return $ids;
    }
}
