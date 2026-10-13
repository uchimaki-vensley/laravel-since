<?php

namespace App\Infrastructure\ReleaseSource;

use App\Application\Releases\GitHubReleaseSource;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GitHubReleaseSourceAdapter implements GitHubReleaseSource
{
    public function __construct(private readonly SafeTarballExtractor $extractor) {}

    /** @return list<array{tag: string, released_at: ?string}> */
    public function stableTags(string $package): array
    {
        [$owner, $repository] = $this->packageParts($package);
        $tags = [];
        $page = 1;

        do {
            $response = $this->request()
                ->retry(3, 1000)
                ->get("/repos/{$owner}/{$repository}/tags", ['per_page' => 100, 'page' => $page])
                ->throw();
            $batch = $response->json();
            if (! is_array($batch)) {
                throw new RuntimeException('GitHub returned an invalid tag list.');
            }

            foreach ($batch as $tag) {
                if (is_array($tag) && is_string($tag['name'] ?? null)) {
                    $tags[] = ['tag' => $tag['name'], 'released_at' => null];
                }
            }
            $page++;
        } while (count($batch) === 100);

        return $tags;
    }

    public function downloadAndExtract(int $releaseId): string
    {
        $release = Release::query()->findOrFail($releaseId);
        [$owner, $repository] = $this->packageParts($release->package);
        $directory = storage_path('app/private/sources/'.$release->id);
        $completionMarker = storage_path('app/private/sources/'.$release->id.'.complete');
        $temporary = storage_path('app/private/downloads/'.Str::uuid().'.tar.gz');
        $maxDownloadBytes = $this->integerConfig('since.max_download_bytes');

        if (is_file($completionMarker) && is_dir($directory)) {
            return $directory;
        }
        if (is_dir($directory)) {
            File::deleteDirectory($directory);
        }

        if (! is_dir(dirname($temporary)) && ! mkdir(dirname($temporary), 0700, true) && ! is_dir(dirname($temporary))) {
            throw new RuntimeException('Could not create temporary download directory.');
        }

        try {
            $response = $this->request()
                ->retry(3, 1000)
                ->withOptions([
                    'sink' => $temporary,
                    'progress' => static function (float $downloadTotal, float $downloadedBytes) use ($maxDownloadBytes): void {
                        if ($downloadedBytes > $maxDownloadBytes) {
                            throw new RuntimeException('GitHub source archive exceeds the download limit.');
                        }
                    },
                ])
                ->get("/repos/{$owner}/{$repository}/tarball/".rawurlencode($release->tag))
                ->throw();
            $downloadSize = is_file($temporary) ? filesize($temporary) : false;
            if (! $response->successful() || ! is_int($downloadSize) || $downloadSize > $maxDownloadBytes) {
                throw new RuntimeException('GitHub source archive exceeds the download limit or is missing.');
            }

            $this->extractor->extract($temporary, $directory);
            if (file_put_contents($completionMarker, 'complete', LOCK_EX) === false) {
                throw new RuntimeException('Could not mark the downloaded source as complete.');
            }

            return $directory;
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function request(): PendingRequest
    {
        $baseUrl = config('since.github_api_url');
        if (! is_string($baseUrl)) {
            throw new RuntimeException('GitHub API URL must be a string.');
        }

        $request = Http::baseUrl($baseUrl)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->withUserAgent('laravel-since')
            ->connectTimeout(10)
            ->timeout($this->integerConfig('since.http_timeout_seconds'));
        $token = config('since.github_token');

        return is_string($token) && $token !== '' ? $request->withToken($token) : $request;
    }

    /** @return array{string, string} */
    private function packageParts(string $package): array
    {
        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D', $package) !== 1) {
            throw new RuntimeException('Invalid GitHub package name.');
        }

        $parts = explode('/', $package, 2);

        return [$parts[0], $parts[1] ?? ''];
    }

    private function integerConfig(string $key): int
    {
        $value = filter_var(config($key), FILTER_VALIDATE_INT);
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("Configuration [{$key}] must be a positive integer.");
        }

        return $value;
    }
}
