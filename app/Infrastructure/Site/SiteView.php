<?php

namespace App\Infrastructure\Site;

use App\Application\Site\SiteLocale;
use App\Application\Site\SitePath;
use Illuminate\Translation\Translator;
use RuntimeException;

/**
 * 1 つのページを描画するときにテンプレートへ渡す、言語とページの場所。
 *
 * 文言は指定した言語の言語ファイル（`lang/{言語}/site.php`）だけから取り、ほかの言語へは落とさない。
 * キーがなければ例外にして、翻訳の抜けたページを出力する前にビルドを止める。
 */
final readonly class SiteView
{
    /**
     * @param  string  $siteUrl  サイトの公開 URL（末尾の `/` なし）。言語ごとの代替リンク（hreflang）に使う
     * @param  SitePath  $path  描画しているページの場所
     */
    public function __construct(
        private Translator $translator,
        private string $siteUrl,
        public SiteLocale $locale,
        public SitePath $path,
    ) {}

    /**
     * @param  array<string, string>  $replace
     */
    public function t(string $key, array $replace = [], ?SiteLocale $locale = null): string
    {
        $locale ??= $this->locale;

        if (! $this->translator->hasForLocale($key, $locale->value)) {
            throw new RuntimeException("Missing translation for {$locale->value}: {$key}");
        }

        return $this->translator->string($key, $replace, $locale->value, false);
    }

    public function url(SitePath $path, ?SiteLocale $locale = null): string
    {
        return $path->url($locale ?? $this->locale);
    }

    public function absoluteUrl(SitePath $path, SiteLocale $locale): string
    {
        return $this->siteUrl.$path->url($locale);
    }

    /**
     * 言語の付かないトップの公開 URL。
     */
    public function entryUrl(): string
    {
        return "{$this->siteUrl}/";
    }

    /**
     * @return list<SiteLocale>
     */
    public function otherLocales(): array
    {
        return array_values(array_filter(SiteLocale::cases(), fn (SiteLocale $locale) => $locale !== $this->locale));
    }

    /**
     * `language.js` に渡す、サイトの言語の一覧（例: `en ja`）。
     */
    public function localeCodes(): string
    {
        return implode(' ', array_map(static fn (SiteLocale $locale) => $locale->value, SiteLocale::cases()));
    }
}
