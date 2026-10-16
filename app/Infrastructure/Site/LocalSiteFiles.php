<?php

namespace App\Infrastructure\Site;

use App\Application\Site\SiteFiles;
use InvalidArgumentException;
use RuntimeException;

/**
 * 作業ディレクトリの中にだけファイルを書く。
 *
 * パスは英数字と `_` `.` `-` からなるセグメントを `/` でつないだ相対パスに限る。
 * セグメントは `.` で始められないので、`..` や隠しファイルでディレクトリの外へ出ることはない。
 * 既存のファイルは上書きしない（同じパスへの 2 回目の書き込みは誤りとして扱う）。
 */
final readonly class LocalSiteFiles implements SiteFiles
{
    private const string PATH = '#\A(?:[A-Za-z0-9_][A-Za-z0-9_.-]*/)*[A-Za-z0-9_][A-Za-z0-9_.-]*\z#';

    public function __construct(
        private string $root,
    ) {}

    public function write(string $path, string $contents): void
    {
        if (preg_match(self::PATH, $path) !== 1 && $path !== LocalSiteOutput::MARKER) {
            throw new InvalidArgumentException("Unsafe site path: {$path}");
        }

        $target = "{$this->root}/{$path}";
        $directory = dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the directory for {$path}.");
        }

        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException("Cannot write {$path}: it already exists.");
        }

        // 'x' は既存のパス（シンボリックリンクを含む）を開かないので、確認との間に作られても上書きしない。
        $handle = @fopen($target, 'xb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write {$path}.");
        }

        try {
            if (fwrite($handle, $contents) !== strlen($contents)) {
                throw new RuntimeException("Cannot write {$path}.");
            }
        } finally {
            fclose($handle);
        }
    }
}
