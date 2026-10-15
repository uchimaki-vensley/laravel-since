<?php

namespace App\Domain\Releases;

use InvalidArgumentException;
use Stringable;

/**
 * 安定版のバージョン（major.minor.patch）。プレリリースは扱わない。
 */
final readonly class Version implements Stringable
{
    private const string PATTERN = '/\A(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})\z/';

    private function __construct(
        public int $major,
        public int $minor,
        public int $patch,
    ) {}

    public static function parse(string $value): self
    {
        return self::tryParse($value)
            ?? throw new InvalidArgumentException("Invalid stable version: {$value}");
    }

    public static function tryParse(string $value): ?self
    {
        if (preg_match(self::PATTERN, $value, $matches) !== 1) {
            return null;
        }

        return new self((int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function isAtLeast(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}";
    }
}
