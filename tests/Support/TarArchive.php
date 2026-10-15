<?php

namespace Tests\Support;

/**
 * テスト用に ustar 形式の tar.gz を組み立てる。
 * PharData などでは作れない、不正なパスや特殊ファイルを含むアーカイブを作るために使う。
 */
final class TarArchive
{
    private string $data = '';

    public function globalPax(string $comment = 'abc123'): self
    {
        return $this->entry('pax_global_header', 'g', self::paxRecord('comment', $comment));
    }

    public function pax(string $key, string $value): self
    {
        return $this->entry('PaxHeader', 'x', self::paxRecord($key, $value));
    }

    public function directory(string $path): self
    {
        return $this->entry($path, '5');
    }

    public function file(string $path, string $contents): self
    {
        return $this->entry($path, '0', $contents);
    }

    public function entry(string $path, string $type, string $contents = '', string $linkName = '', string $prefix = ''): self
    {
        $this->data .= self::header($path, $type, strlen($contents), $linkName, $prefix);
        $this->data .= $contents.str_repeat("\0", (512 - strlen($contents) % 512) % 512);

        return $this;
    }

    public function raw(string $bytes): self
    {
        $this->data .= $bytes;

        return $this;
    }

    public function tar(): string
    {
        return $this->data.str_repeat("\0", 1024);
    }

    public function gzip(): string
    {
        return (string) gzencode($this->tar());
    }

    public static function header(string $path, string $type, int $size, string $linkName = '', string $prefix = ''): string
    {
        $header = str_pad($path, 100, "\0")
            .str_pad('0000644', 8, "\0")
            .str_pad('0000000', 8, "\0")
            .str_pad('0000000', 8, "\0")
            .str_pad(sprintf('%011o', $size), 12, "\0")
            .str_pad('00000000000', 12, "\0")
            .str_repeat(' ', 8)
            .$type
            .str_pad($linkName, 100, "\0")
            ."ustar\x0000"
            .str_repeat("\0", 64)
            .str_repeat("\0", 16)
            .str_pad($prefix, 155, "\0");
        $header = str_pad($header, 512, "\0");
        $checksum = array_sum(array_map(ord(...), str_split($header)));

        return substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
    }

    private static function paxRecord(string $key, string $value): string
    {
        $body = " {$key}={$value}\n";
        $length = strlen($body) + 1;

        while (strlen((string) $length) + strlen($body) !== $length) {
            $length++;
        }

        return $length.$body;
    }
}
