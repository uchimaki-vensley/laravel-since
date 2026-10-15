<?php

namespace App\Infrastructure\ReleaseSources;

use App\Application\Releases\ReleaseSourceStore;
use App\Application\Releases\ReleaseSummary;
use App\Infrastructure\Archives\SafeTarGzExtractor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 展開済みソースを `{root}/{vendor}/{name}/{version}` に置く。
 *
 * 一時ディレクトリで展開し終えてから rename で公開するので、途中で失敗しても
 * 展開途中のディレクトリが「展開済み」に見えることはない。
 */
final readonly class LocalReleaseSourceStore implements ReleaseSourceStore
{
    public function __construct(
        private Filesystem $files,
        private SafeTarGzExtractor $extractor,
        private string $root,
    ) {}

    public function has(ReleaseSummary $release): bool
    {
        return $this->files->isDirectory($this->path($release));
    }

    public function path(ReleaseSummary $release): string
    {
        // PackageName と Version は形式を検証済みなので、`..` や区切り文字を含まない。
        return "{$this->root}/{$release->package}/{$release->tag->version}";
    }

    public function put(ReleaseSummary $release, callable $writeArchive): void
    {
        $work = "{$this->root}/.tmp/".Str::random(32);
        $archive = "{$work}/source.tar.gz";
        $extracted = "{$work}/source";

        $this->files->ensureDirectoryExists($extracted);

        try {
            $writeArchive($archive);
            $this->extractor->extract($archive, $extracted);

            $target = $this->path($release);
            $this->files->ensureDirectoryExists(dirname($target));

            // 並行して同じリリースを展開し終えたプロセスがあれば、そちらを残す。
            if (! $this->files->isDirectory($target) && ! @rename($extracted, $target)) {
                throw new RuntimeException("Cannot move the extracted source to {$target}.");
            }
        } finally {
            $this->files->deleteDirectory($work);
        }
    }
}
