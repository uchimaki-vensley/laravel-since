<?php

namespace App\Domain\Releases;

/**
 * 安定版を指す Git タグ（例: v13.3.0）。
 */
final readonly class ReleaseTag
{
    private function __construct(
        public string $name,
        public Version $version,
    ) {}

    /**
     * 安定版のタグなら値を返し、プレリリースや形式の異なるタグなら null を返す。
     */
    public static function tryParse(string $name): ?self
    {
        if (! str_starts_with($name, 'v')) {
            return null;
        }

        $version = Version::tryParse(substr($name, 1));

        return $version === null ? null : new self($name, $version);
    }
}
