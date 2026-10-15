<?php

namespace App\Application\Releases;

use RuntimeException;

final readonly class ReleaseSourceParserService implements ReleaseSourceParser
{
    public function __construct(
        private ReleaseRepository $releases,
        private ReleaseSourceStore $sources,
        private SourceParser $parser,
        private ApiSnapshotRepository $snapshots,
    ) {}

    public function parse(int $releaseId): void
    {
        $release = $this->releases->find($releaseId);

        if ($release === null) {
            return;
        }

        if (! $this->sources->has($release)) {
            throw new RuntimeException("The source of {$release->tag->name} has not been fetched.");
        }

        $this->snapshots->replace($release, $this->parser->parse($this->sources->path($release)));
    }
}
