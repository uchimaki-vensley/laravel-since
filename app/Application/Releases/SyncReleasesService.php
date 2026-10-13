<?php

namespace App\Application\Releases;

class SyncReleasesService implements SyncReleases
{
    public function __construct(
        private readonly ReleaseRepository $releases,
        private readonly GitHubReleaseSource $source,
        private readonly ReleaseSyncSettings $settings,
    ) {}

    public function execute(): array
    {
        $package = $this->settings->package;
        $minimum = $this->settings->minimumVersion;

        foreach ($this->source->stableTags($package) as $tag) {
            $version = str_starts_with($tag['tag'], 'v') ? substr($tag['tag'], 1) : $tag['tag'];
            if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1 || version_compare($version, $minimum, '<')) {
                continue;
            }

            $this->releases->updateOrCreate([
                'package' => $package,
                'version' => $version,
                'tag' => $tag['tag'],
                'released_at' => $tag['released_at'],
            ]);
        }

        return $this->releases->unparsed($package);
    }
}
