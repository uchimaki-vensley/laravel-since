<?php

namespace App\Infrastructure\Site;

use App\Application\Site\SiteOutput;
use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * サイトをローカルのディレクトリ（既定 `dist/`）に書き出す。
 *
 * 出力先と同じ親ディレクトリの一時ディレクトリに書き終えてから rename で置き換えるので、
 * 途中で失敗しても前回の出力はそのまま残る。誤った設定で無関係なディレクトリを消さないよう、
 * 既存の出力先は、このクラスが書いた印（`.since-site`）があるか空の場合だけ置き換える。
 */
final readonly class LocalSiteOutput implements SiteOutput
{
    public const string MARKER = '.since-site';

    /**
     * @param  string  $assets  そのまま出力に含める静的ファイル（CSS・JS・`_headers`・`_redirects`）のディレクトリ
     * @param  string  $destination  出力先
     */
    public function __construct(
        private Filesystem $files,
        private string $assets,
        private string $destination,
    ) {}

    public function replace(callable $build): void
    {
        $destination = rtrim($this->destination, '/');
        $parent = dirname($destination);

        if ($destination === '' || ! $this->files->isDirectory($parent)) {
            throw new RuntimeException("The parent directory of the site output does not exist: {$parent}");
        }

        if (is_link($destination) || $this->files->exists($destination) && ! $this->isReplaceable($destination)) {
            throw new RuntimeException("Refusing to replace {$destination}: it is not a site output of since:build.");
        }

        $work = "{$parent}/.".basename($destination).'.building-'.Str::random(16);
        $this->files->makeDirectory($work);

        try {
            $site = new LocalSiteFiles($work);

            foreach ($this->assetFiles() as $path => $file) {
                $site->write($path, $this->files->get($file));
            }

            $build($site);
            $site->write(self::MARKER, '');
            $this->swap($work, $destination);
        } finally {
            $this->files->deleteDirectory($work);
        }
    }

    private function isReplaceable(string $destination): bool
    {
        return $this->files->isDirectory($destination)
            && ($this->files->isFile("{$destination}/".self::MARKER) || (scandir($destination) ?: []) === ['.', '..']);
    }

    /**
     * @return array<string, string> サイト内の相対パス => 元のファイル
     */
    private function assetFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->assets, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $files[substr($file->getPathname(), strlen($this->assets) + 1)] = $file->getPathname();
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    private function swap(string $work, string $destination): void
    {
        if (! $this->files->exists($destination)) {
            $this->rename($work, $destination);

            return;
        }

        $old = dirname($destination).'/.'.basename($destination).'.old-'.Str::random(16);
        $this->rename($destination, $old);

        try {
            $this->rename($work, $destination);
        } catch (RuntimeException $exception) {
            $this->rename($old, $destination);

            throw $exception;
        }

        $this->files->deleteDirectory($old);
    }

    private function rename(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new RuntimeException("Cannot move {$from} to {$to}.");
        }
    }
}
