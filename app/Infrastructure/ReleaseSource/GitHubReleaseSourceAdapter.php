<?php

namespace App\Infrastructure\ReleaseSource;

use App\Application\Releases\GitHubReleaseSource;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
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
            $response = $this->getWithRetries(
                "/repos/{$owner}/{$repository}/tags",
                ['per_page' => 100, 'page' => $page],
            );
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
            $response = $this->getWithRetries(
                "/repos/{$owner}/{$repository}/tarball/".rawurlencode($release->tag),
                options: [
                    'sink' => $temporary,
                    'progress' => static function (float $downloadTotal, float $downloadedBytes) use ($maxDownloadBytes): void {
                        if ($downloadedBytes > $maxDownloadBytes) {
                            throw new RuntimeException('GitHub source archive exceeds the download limit.');
                        }
                    },
                ],
            );
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

    /**
     * @param  array<string, int|string>  $query
     * @param  array<string, mixed>  $options
     */
    private function getWithRetries(string $url, array $query = [], array $options = []): Response
    {
        $transientFailures = 0;
        $rateLimitFailures = 0;

        while (true) {
            $this->reserveGitHubRequest();

            try {
                $request = $this->request();
                if ($options !== []) {
                    $request = $request->withOptions($options);
                }
                $response = $request->get($url, $query);
            } catch (ConnectionException $exception) {
                if (++$transientFailures >= 3) {
                    throw $exception;
                }
                Sleep::sleep(1);

                continue;
            }

            $rateLimitDelay = $this->rateLimitRetryDelay($response, $rateLimitFailures);
            if ($rateLimitDelay !== null) {
                if (++$rateLimitFailures > 3) {
                    return $response->throw();
                }
                Sleep::sleep($rateLimitDelay);

                continue;
            }

            if ($response->serverError() && ++$transientFailures < 3) {
                Sleep::sleep(1);

                continue;
            }

            return $response->throw();
        }
    }

    private function rateLimitRetryDelay(Response $response, int $failures): ?int
    {
        if ($response->status() !== 403 && $response->status() !== 429) {
            return null;
        }

        $remaining = $response->header('x-ratelimit-remaining');
        $retryAfter = filter_var($response->header('retry-after'), FILTER_VALIDATE_INT);
        $message = $response->json('message');
        if ($remaining !== '0' && ! is_int($retryAfter) && $response->status() !== 429
            && (! is_string($message) || ! str_contains(strtolower($message), 'rate limit'))) {
            return null;
        }

        $delay = is_int($retryAfter) ? max(1, $retryAfter) : 0;
        if ($remaining === '0') {
            $resetAt = filter_var($response->header('x-ratelimit-reset'), FILTER_VALIDATE_INT);
            $serverTime = strtotime($response->header('date'));
            $delay = max($delay, is_int($resetAt) ? $resetAt - (is_int($serverTime) ? $serverTime : time()) + 1 : 60);
        }

        return $delay > 0 ? $delay : 60 * (2 ** $failures);
    }

    private function reserveGitHubRequest(): void
    {
        while (! RateLimiter::attempt('github-api', 30, static fn (): bool => true, 60)) {
            Sleep::sleep(max(1, RateLimiter::availableIn('github-api')));
        }
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
