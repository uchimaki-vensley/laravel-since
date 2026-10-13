<?php

namespace App\Application\Releases;

interface ReleaseRepository
{
    /** @param array{package: string, version: string, tag: string, released_at: ?string} $attributes */
    public function updateOrCreate(array $attributes): int;

    /** @return list<int> */
    public function unparsed(string $package): array;
}
