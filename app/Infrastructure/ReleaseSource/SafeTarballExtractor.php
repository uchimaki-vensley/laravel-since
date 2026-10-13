<?php

namespace App\Infrastructure\ReleaseSource;

use PharData;
use PharFileInfo;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

class SafeTarballExtractor
{
    public function extract(string $archivePath, string $destination): void
    {
        $maxFileBytes = $this->integerConfig('since.max_archive_file_bytes');
        $maxTotalBytes = $this->integerConfig('since.max_archive_bytes');
        $maxEntries = $this->integerConfig('since.max_archive_entries');
        $archive = null;
        $entries = [];
        $root = null;
        $totalBytes = 0;
        $entryCount = 0;

        try {
            $this->validateTarHeaders($archivePath, $maxFileBytes, $maxTotalBytes, $maxEntries);
            $archive = new PharData($archivePath);
            $iterator = new RecursiveIteratorIterator($archive, RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                if (! $entry instanceof PharFileInfo) {
                    throw new RuntimeException('Unsupported archive entry.');
                }

                $entryCount++;
                if ($entryCount > $maxEntries) {
                    throw new RuntimeException('Archive contains too many entries.');
                }

                $name = $this->normalizeName($iterator->getSubPathname());
                if ($name === '') {
                    continue;
                }

                $parts = explode('/', rtrim($name, '/'));
                $entryRoot = array_shift($parts);
                if ($entryRoot === '' || str_contains($entryRoot, ':')) {
                    throw new RuntimeException('Archive must contain a single top-level directory.');
                }
                if ($root !== null && $root !== $entryRoot) {
                    throw new RuntimeException('Archive contains unexpected top-level entries.');
                }
                $root = $entryRoot;

                if ($entry->isLink()) {
                    throw new RuntimeException('Symbolic links are not allowed in source archives.');
                }
                if (! $entry->isDir() && ! $entry->isFile()) {
                    throw new RuntimeException('Special files are not allowed in source archives.');
                }

                $size = $entry->isDir() ? 0 : $entry->getSize();
                if (! is_int($size) || $size < 0) {
                    throw new RuntimeException('Archive contains an invalid file size.');
                }
                if ($size > $maxFileBytes) {
                    throw new RuntimeException('Archive contains an oversized file.');
                }
                $totalBytes += $size;
                if ($totalBytes > $maxTotalBytes) {
                    throw new RuntimeException('Archive expands beyond the allowed size.');
                }

                $relative = implode('/', $parts);
                if ($relative === '' && ! $entry->isDir()) {
                    throw new RuntimeException('Archive top-level entry must be a directory.');
                }
                if ($relative !== '') {
                    $entries[] = [$entry, $relative, $size];
                }
            }

            if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
                throw new RuntimeException('Could not create source directory.');
            }

            foreach ($entries as [$entry, $relative, $size]) {
                $target = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $parent = dirname($target);
                if (! is_dir($parent) && ! mkdir($parent, 0700, true) && ! is_dir($parent)) {
                    throw new RuntimeException('Could not create source subdirectory.');
                }
                if ($entry->isDir()) {
                    continue;
                }

                $contents = $entry->getContent();
                if (strlen($contents) !== $size || file_put_contents($target, $contents, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write source file.');
                }
                chmod($target, 0600);
            }
        } catch (Throwable $exception) {
            $this->removeDirectory($destination);
            throw new RuntimeException('Could not safely extract source archive.', previous: $exception);
        } finally {
            unset($archive);
        }
    }

    private function normalizeName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        if (str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name) === 1 || str_contains($name, "\0")) {
            throw new RuntimeException('Archive contains an absolute or invalid path.');
        }

        $parts = explode('/', $name);
        foreach ($parts as $part) {
            if ($part === '..') {
                throw new RuntimeException('Archive path traversal is not allowed.');
            }
        }

        return implode('/', array_values(array_filter($parts, static fn (string $part): bool => $part !== '' && $part !== '.')));
    }

    private function validateTarHeaders(string $archivePath, int $maxFileBytes, int $maxTotalBytes, int $maxEntries): void
    {
        $gzip = str_ends_with($archivePath, '.gz');
        $stream = $gzip ? gzopen($archivePath, 'rb') : fopen($archivePath, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Could not open source archive.');
        }

        $entryCount = 0;
        $totalBytes = 0;

        try {
            while (true) {
                $header = $gzip ? gzread($stream, 512) : fread($stream, 512);
                if ($header === '' || $header === false) {
                    break;
                }
                if (strlen($header) !== 512) {
                    throw new RuntimeException('Archive contains an incomplete tar header.');
                }
                if (trim($header, "\0") === '') {
                    break;
                }

                $entryCount++;
                if ($entryCount > $maxEntries) {
                    throw new RuntimeException('Archive contains too many entries.');
                }

                $name = $this->tarField($header, 0, 100);
                $prefix = $this->tarField($header, 345, 155);
                $path = $prefix === '' ? $name : $prefix.'/'.$name;
                $this->normalizeName($path);

                $size = $this->tarSize(substr($header, 124, 12));
                $type = $header[156] ?? "\0";
                if ($type === 'x' || $type === 'g') {
                    if ($size > $maxFileBytes) {
                        throw new RuntimeException('Archive metadata exceeds the allowed size.');
                    }
                    $metadata = $this->readTarBytes($stream, $size, $gzip);
                    foreach ($this->parsePaxMetadata($metadata) as $key => $value) {
                        if ($key === 'path' && $value !== '') {
                            $this->normalizeName($value);
                        }
                        if ($key === 'size') {
                            throw new RuntimeException('PAX size overrides are not supported.');
                        }
                        if ($key === 'linkpath' && $value !== '') {
                            throw new RuntimeException('Archive links are not allowed.');
                        }
                    }
                    $this->skipTarPadding($stream, $size, $gzip, contentRead: true);

                    continue;
                }

                if ($type !== "\0" && $type !== '0' && $type !== '5') {
                    throw new RuntimeException('Links and special files are not allowed in source archives.');
                }
                if ($type === '5' && $size !== 0) {
                    throw new RuntimeException('Archive directory entries cannot contain file data.');
                }
                if ($size > $maxFileBytes) {
                    throw new RuntimeException('Archive contains an oversized file.');
                }
                if ($type !== '5') {
                    $totalBytes += $size;
                    if ($totalBytes > $maxTotalBytes) {
                        throw new RuntimeException('Archive expands beyond the allowed size.');
                    }
                }

                $this->skipTarPadding($stream, $size, $gzip);
            }
        } finally {
            $gzip ? gzclose($stream) : fclose($stream);
        }
    }

    private function tarField(string $header, int $offset, int $length): string
    {
        $value = substr($header, $offset, $length);
        $terminator = strpos($value, "\0");

        return trim($terminator === false ? $value : substr($value, 0, $terminator));
    }

    private function tarSize(string $field): int
    {
        $value = trim($field, "\0 ");
        if ($value === '') {
            return 0;
        }
        if (preg_match('/^[0-7]+$/D', $value) !== 1) {
            throw new RuntimeException('Archive contains an invalid file size.');
        }

        $size = octdec($value);
        if (! is_int($size)) {
            throw new RuntimeException('Archive contains an unsupported file size.');
        }

        return $size;
    }

    /** @param resource $stream */
    private function readTarBytes(mixed $stream, int $length, bool $gzip): string
    {
        $contents = '';
        while (strlen($contents) < $length) {
            $remaining = max(1, $length - strlen($contents));
            $chunk = $gzip ? gzread($stream, $remaining) : fread($stream, $remaining);
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Archive contains truncated file data.');
            }
            $contents .= $chunk;
        }

        return $contents;
    }

    /** @param resource $stream */
    private function skipTarPadding(mixed $stream, int $length, bool $gzip, bool $contentRead = false): void
    {
        $paddedLength = (int) (ceil($length / 512) * 512);
        $remaining = $contentRead ? $paddedLength - $length : $paddedLength;
        while ($remaining > 0) {
            $chunk = $gzip ? gzread($stream, min($remaining, 8192)) : fread($stream, min($remaining, 8192));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('Archive contains truncated file data.');
            }
            $remaining -= strlen($chunk);
        }
    }

    /** @return array<string, string> */
    private function parsePaxMetadata(string $metadata): array
    {
        $values = [];
        $offset = 0;
        $length = strlen($metadata);
        while ($offset < $length) {
            $space = strpos($metadata, ' ', $offset);
            if ($space === false) {
                throw new RuntimeException('Archive contains malformed PAX metadata.');
            }
            $recordLength = substr($metadata, $offset, $space - $offset);
            if (preg_match('/^[1-9][0-9]*$/D', $recordLength) !== 1) {
                throw new RuntimeException('Archive contains malformed PAX metadata.');
            }
            $recordEnd = $offset + (int) $recordLength;
            if ($recordEnd > $length || $recordEnd <= $space + 1) {
                throw new RuntimeException('Archive contains malformed PAX metadata.');
            }
            $record = substr($metadata, $space + 1, $recordEnd - $space - 2);
            $separator = strpos($record, '=');
            if ($separator !== false) {
                $values[substr($record, 0, $separator)] = substr($record, $separator + 1);
            }
            $offset = $recordEnd;
        }

        return $values;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo) {
                throw new RuntimeException('Could not safely inspect extracted source directory.');
            }
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
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
