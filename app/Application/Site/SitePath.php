<?php

namespace App\Application\Site;

use App\Domain\Releases\Version;

/**
 * サイト内のページの場所（言語を含まない）。
 *
 * URL とファイルのパスは言語を渡して作る。言語の付かないリンクを出力しないよう、文字列には変換できない
 * （Blade で `{{ $path }}` と書くとビルドが失敗する）。
 *
 * 型名はファイルのパスになるので、PHP の識別子（ASCII）だけからなる名前空間付きの名前に限る。
 * それ以外の名前（`..` や区切り文字を含むものなど）はページにしない。
 */
final readonly class SitePath
{
    private const string IDENTIFIER = '/\A[A-Za-z_][A-Za-z0-9_]*\z/';

    private function __construct(
        private string $directory,
        private string $fragment = '',
    ) {}

    public static function home(): self
    {
        return new self('');
    }

    public static function release(Version $version): self
    {
        // Version は数字とドットだけからなる。
        return new self("releases/{$version}");
    }

    /**
     * 型のページ（例: `types/Illuminate/Support/Str`）。パスにできない名前なら null。
     */
    public static function type(string $fqcn): ?self
    {
        $segments = explode('\\', $fqcn);

        foreach ($segments as $segment) {
            if (preg_match(self::IDENTIFIER, $segment) !== 1) {
                return null;
            }
        }

        return new self('types/'.implode('/', $segments));
    }

    /**
     * ページの中の場所（メソッドの見出し）を指す。
     */
    public function withFragment(string $fragment): self
    {
        return new self($this->directory, $fragment);
    }

    /**
     * サイト内の URL（例: `/ja/types/Illuminate/Support/Str/#slug`）。
     */
    public function url(SiteLocale $locale): string
    {
        return '/'.$locale->value.'/'.$this->directoryWithSlash()
            .($this->fragment === '' ? '' : '#'.rawurlencode($this->fragment));
    }

    /**
     * 出力するファイル（例: `ja/types/Illuminate/Support/Str/index.html`）。
     */
    public function file(SiteLocale $locale): string
    {
        return $locale->value.'/'.$this->directoryWithSlash().'index.html';
    }

    private function directoryWithSlash(): string
    {
        return $this->directory === '' ? '' : "{$this->directory}/";
    }
}
