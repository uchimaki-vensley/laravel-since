<?php

namespace App\Application\Releases;

final readonly class ReleaseSourceFetcherService implements ReleaseSourceFetcher
{
    public function __construct(
        private ReleaseRepository $releases,
        private SourceArchiveDownloader $downloader,
        private ReleaseSourceStore $sources,
    ) {}

    public function fetch(int $releaseId): void
    {
        $release = $this->releases->find($releaseId);

        if ($release === null || $this->sources->has($release)) {
            return;
        }

        $this->sources->put(
            $release,
            fn (string $archive) => $this->downloader->download($release->package, $release->tag, $archive),
        );
    }
}
