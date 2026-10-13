<?php

namespace App\Application\Releases;

interface GitHubReleaseSource
{
    /** @return list<array{tag: string, released_at: ?string}> */
    public function stableTags(string $package): array;

    public function downloadAndExtract(int $releaseId): string;
}
