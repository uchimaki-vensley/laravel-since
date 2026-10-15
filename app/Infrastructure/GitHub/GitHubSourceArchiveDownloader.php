<?php

namespace App\Infrastructure\GitHub;

use App\Application\Releases\SourceArchiveDownloader;
use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;
use RuntimeException;

/**
 * codeload.github.com からタグの tarball を取得する。トークンは送らない（公開リポジトリなので不要）。
 */
final readonly class GitHubSourceArchiveDownloader implements SourceArchiveDownloader
{
    public function __construct(
        private GitHubHttp $http,
        private string $archiveUrl,
        private int $maxBytes,
    ) {}

    public function download(PackageName $package, ReleaseTag $tag, string $destination): void
    {
        $maxBytes = $this->maxBytes;

        $this->http->request()
            ->baseUrl($this->archiveUrl)
            ->withOptions([
                // 受信中に上限を超えたら転送を打ち切る（Content-Length を信用しない）。
                'progress' => static function (int $expected, int $downloaded) use ($maxBytes): void {
                    if ($expected > $maxBytes || $downloaded > $maxBytes) {
                        throw new RuntimeException("The source archive exceeds {$maxBytes} bytes.");
                    }
                },
            ])
            ->sink($destination)
            ->get("/{$package}/tar.gz/refs/tags/{$tag->name}")
            ->throw();

        clearstatcache(true, $destination);

        if (! is_file($destination) || filesize($destination) > $maxBytes) {
            throw new RuntimeException("The source archive exceeds {$maxBytes} bytes.");
        }
    }
}
