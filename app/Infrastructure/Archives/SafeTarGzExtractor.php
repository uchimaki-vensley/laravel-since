<?php

namespace App\Infrastructure\Archives;

/**
 * tar.gz を 1 エントリずつ検証しながら展開する。
 *
 * 通常ファイルとディレクトリだけを書き出し、リンク・デバイスなどの特殊ファイル、
 * 展開先の外を指すパス、上限を超えるサイズやエントリ数を拒否する。
 * 展開したファイルは読み込み・実行しない（docs/security.md）。
 */
final class SafeTarGzExtractor
{
    private const int BLOCK_SIZE = 512;

    private const int CHUNK_SIZE = 65536;

    /** pax 拡張ヘッダーの上限。GitHub の tarball ではコミット ID と長いパスにしか使われない。 */
    private const int MAX_PAX_BYTES = 65536;

    /** @var resource|null */
    private $stream = null;

    private int $readBytes = 0;

    /** 最初のエントリで決まる、全エントリに共通する先頭ディレクトリ名 */
    private ?string $root = null;

    public function __construct(
        private readonly int $maxFileBytes,
        private readonly int $maxTotalBytes,
        private readonly int $maxEntries,
    ) {}

    /**
     * $archive を空のディレクトリ $destination に展開する。
     * 全エントリに共通する先頭ディレクトリ（GitHub の tarball では `framework-13.3.0/` など）は取り除く。
     *
     * @throws UnsafeArchiveException
     */
    public function extract(string $archive, string $destination): void
    {
        if (is_link($destination) || ! is_dir($destination) || scandir($destination) !== ['.', '..']) {
            throw new UnsafeArchiveException('The destination must be an existing empty directory.');
        }

        $stream = @gzopen($archive, 'rb');

        if ($stream === false) {
            throw new UnsafeArchiveException('Cannot open the archive.');
        }

        $this->stream = $stream;
        $this->readBytes = 0;
        $this->root = null;

        try {
            $this->extractEntries($destination);
        } finally {
            gzclose($stream);
            $this->stream = null;
        }
    }

    private function extractEntries(string $destination): void
    {
        $paxPath = null;
        $entries = 0;

        while (true) {
            $block = $this->read(self::BLOCK_SIZE);

            if ($block === str_repeat("\0", self::BLOCK_SIZE)) {
                return;
            }

            if (++$entries > $this->maxEntries) {
                throw new UnsafeArchiveException('The archive has too many entries.');
            }

            $header = $this->parseHeader($block);

            switch ($header['type']) {
                case 'g':
                    $this->readPax($header['size']);

                    continue 2;
                case 'x':
                    $paxPath = $this->paxPath($this->readPax($header['size']));

                    continue 2;
                case '0':
                case "\0":
                    $path = $paxPath ?? $header['path'];
                    $paxPath = null;
                    $relative = $this->relativePath($path);

                    if ($relative === null) {
                        throw new UnsafeArchiveException("A file must be inside the top-level directory: {$path}");
                    }

                    $this->writeFile($destination.'/'.$relative, $header['size']);

                    continue 2;
                case '5':
                    $path = $paxPath ?? $header['path'];
                    $paxPath = null;
                    $relative = $this->relativePath(rtrim($path, '/'));
                    $this->skip($header['size']);

                    if ($relative !== null) {
                        $this->makeDirectory($destination.'/'.$relative);
                    }

                    continue 2;
                default:
                    throw new UnsafeArchiveException(sprintf(
                        'Unsupported entry type "%s" (links and special files are rejected): %s',
                        $header['type'],
                        $paxPath ?? $header['path'],
                    ));
            }
        }
    }

    /**
     * @return array{path: string, size: int, type: string}
     */
    private function parseHeader(string $block): array
    {
        if (! str_starts_with(substr($block, 257, 6), 'ustar')) {
            throw new UnsafeArchiveException('Only ustar / pax archives are supported.');
        }

        $checksum = $this->octal(substr($block, 148, 8), 'checksum');
        $actual = array_sum(array_map(ord(...), str_split(substr_replace($block, str_repeat(' ', 8), 148, 8))));

        if ($checksum !== $actual) {
            throw new UnsafeArchiveException('The archive has a broken header.');
        }

        $name = $this->cString(substr($block, 0, 100));
        $prefix = $this->cString(substr($block, 345, 155));

        return [
            'path' => $prefix === '' ? $name : $prefix.'/'.$name,
            'size' => $this->octal(substr($block, 124, 12), 'size'),
            'type' => $block[156],
        ];
    }

    /**
     * 先頭ディレクトリを取り除いた、展開先からの相対パスを返す。先頭ディレクトリそのものなら null。
     */
    private function relativePath(string $path): ?string
    {
        if ($path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1
            || preg_match('/\A[A-Za-z]:/', $path) === 1) {
            throw new UnsafeArchiveException("Unsafe path in the archive: {$path}");
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new UnsafeArchiveException("Unsafe path in the archive: {$path}");
            }
        }

        $first = array_shift($segments);
        $this->root ??= $first;

        if ($first !== $this->root) {
            throw new UnsafeArchiveException("Every entry must share one top-level directory: {$path}");
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    private function writeFile(string $target, int $size): void
    {
        if ($size > $this->maxFileBytes) {
            throw new UnsafeArchiveException("A file in the archive is too large: {$size} bytes.");
        }

        $this->makeDirectory(dirname($target));

        if (file_exists($target) || is_link($target)) {
            throw new UnsafeArchiveException("The archive has a duplicate entry: {$target}");
        }

        // 'x' は既存のパス（シンボリックリンクを含む）があれば失敗するので、確認後に置き換えられても上書きしない。
        $file = @fopen($target, 'xb');

        if ($file === false) {
            throw new UnsafeArchiveException("Cannot create a file from the archive: {$target}");
        }

        try {
            for ($remaining = $size; $remaining > 0; $remaining -= self::CHUNK_SIZE) {
                if (fwrite($file, $this->read(min(self::CHUNK_SIZE, $remaining))) === false) {
                    throw new UnsafeArchiveException("Cannot write a file from the archive: {$target}");
                }
            }
        } finally {
            fclose($file);
        }

        $this->read($this->padding($size));
    }

    private function makeDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
            throw new UnsafeArchiveException("Cannot create a directory from the archive: {$directory}");
        }
    }

    private function readPax(int $size): string
    {
        if ($size > self::MAX_PAX_BYTES) {
            throw new UnsafeArchiveException('The archive has an oversized pax header.');
        }

        $data = $this->read($size);
        $this->read($this->padding($size));

        return $data;
    }

    /**
     * pax 拡張ヘッダーのうち path だけを使う。サイズやリンク先の上書きには対応せず拒否する。
     */
    private function paxPath(string $data): ?string
    {
        $path = null;

        for ($offset = 0; $offset < strlen($data);) {
            $space = strpos($data, ' ', $offset);
            $length = $space === false ? 0 : (int) substr($data, $offset, $space - $offset);

            if ($space === false || $length < $space - $offset + 2 || $offset + $length > strlen($data)) {
                throw new UnsafeArchiveException('The archive has a broken pax header.');
            }

            $record = substr($data, $space + 1, $length - ($space - $offset) - 2);
            [$key, $value] = array_pad(explode('=', $record, 2), 2, null);

            if ($value === null || $data[$offset + $length - 1] !== "\n") {
                throw new UnsafeArchiveException('The archive has a broken pax header.');
            }

            match ($key) {
                'path' => $path = $value,
                'size', 'linkpath' => throw new UnsafeArchiveException("Unsupported pax header: {$key}"),
                default => null,
            };

            $offset += $length;
        }

        return $path;
    }

    private function skip(int $size): void
    {
        $total = $size + $this->padding($size);

        for ($remaining = $total; $remaining > 0; $remaining -= self::CHUNK_SIZE) {
            $this->read(min(self::CHUNK_SIZE, $remaining));
        }
    }

    /**
     * 展開後のバイト数を数えながら読む。上限を超えたら、残りを展開せずに止める。
     */
    private function read(int $length): string
    {
        if ($length === 0) {
            return '';
        }

        $this->readBytes += $length;

        if ($this->readBytes > $this->maxTotalBytes) {
            throw new UnsafeArchiveException('The extracted archive is too large.');
        }

        $data = '';

        while (strlen($data) < $length) {
            $chunk = $this->stream === null ? false : gzread($this->stream, $length - strlen($data));

            if ($chunk === false || $chunk === '') {
                throw new UnsafeArchiveException('The archive ended unexpectedly.');
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function padding(int $size): int
    {
        return (self::BLOCK_SIZE - $size % self::BLOCK_SIZE) % self::BLOCK_SIZE;
    }

    private function octal(string $field, string $name): int
    {
        $value = trim($field, " \0");

        // 12 桁を超える値や base-256 表記は、上限を超える大きさなので扱わない。
        if (preg_match('/\A[0-7]{1,11}\z/', $value) !== 1) {
            throw new UnsafeArchiveException("The archive has an invalid {$name} field.");
        }

        return (int) octdec($value);
    }

    private function cString(string $field): string
    {
        $end = strpos($field, "\0");

        return $end === false ? $field : substr($field, 0, $end);
    }
}
