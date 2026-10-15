<?php

namespace App\Application\Releases;

use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;
use App\Domain\Releases\Version;

final readonly class ReleaseSynchronizerService implements ReleaseSynchronizer
{
    public function __construct(
        private ReleaseTagSource $tags,
        private ReleaseRepository $releases,
        private PackageName $package,
        private Version $minimumVersion,
    ) {}

    public function sync(): SyncResult
    {
        $targets = [];

        foreach ($this->tags->tagNames($this->package) as $name) {
            $tag = ReleaseTag::tryParse($name);

            if ($tag !== null && $tag->version->isAtLeast($this->minimumVersion)) {
                $targets[] = $tag;
            }
        }

        $registered = $this->releases->registerMissing($this->package, $targets);

        return new SyncResult($registered, $this->releases->unparsed($this->package));
    }
}
