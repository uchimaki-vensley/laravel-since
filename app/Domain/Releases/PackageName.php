<?php

namespace App\Domain\Releases;

use InvalidArgumentException;
use Stringable;

/**
 * Composer のパッケージ名（vendor/name）。GitHub のリポジトリ名と同じものを対象にする。
 */
final readonly class PackageName implements Stringable
{
    private const string PATTERN = '/\A[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z/';

    public string $value;

    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("Invalid package name: {$value}");
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
