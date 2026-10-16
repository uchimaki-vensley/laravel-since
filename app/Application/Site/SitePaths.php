<?php

namespace App\Application\Site;

use App\Domain\Releases\Version;

/**
 * サイトの URL とファイルの置き場所。
 *
 * 型名はファイルのパスになるので、PHP の識別子（ASCII）だけからなる名前空間付きの名前に限る。
 * それ以外の名前（`..` や区切り文字を含むものなど）はページにしない。
 */
final class SitePaths
{
    private const string IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    /**
     * 型のページのディレクトリ（例: `types/Illuminate/Support/Str`）。パスにできない名前なら null。
     */
    public static function typeDirectory(string $fqcn): ?string
    {
        $segments = explode('\\', $fqcn);

        foreach ($segments as $segment) {
            if (preg_match(self::IDENTIFIER, $segment) !== 1) {
                return null;
            }
        }

        return 'types/'.implode('/', $segments);
    }

    public static function releaseDirectory(Version $version): string
    {
        // Version は数字とドットだけからなる。
        return "releases/{$version}";
    }

    public static function url(string $directory): string
    {
        return "/{$directory}/";
    }

    public static function page(string $directory): string
    {
        return "{$directory}/index.html";
    }
}
