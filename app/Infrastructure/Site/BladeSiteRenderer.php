<?php

namespace App\Infrastructure\Site;

use App\Application\Site\Pages\IndexPage;
use App\Application\Site\Pages\ReleasePage;
use App\Application\Site\Pages\TypePage;
use App\Application\Site\SiteLocale;
use App\Application\Site\SitePath;
use App\Application\Site\SiteRenderer;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;

/**
 * Blade（`resources/views/site`）でページを HTML にする。テンプレートでは `{{ }}` だけを使い、値を必ずエスケープする。
 */
final readonly class BladeSiteRenderer implements SiteRenderer
{
    public function __construct(
        private Factory $views,
        private Translator $translator,
        private string $publicUrl,
    ) {}

    public function index(IndexPage $page, SiteLocale $locale): string
    {
        return $this->render('site.index', $page, $locale, SitePath::home());
    }

    public function release(ReleasePage $page, SiteLocale $locale): string
    {
        return $this->render('site.release', $page, $locale, $page->release->path);
    }

    public function type(TypePage $page, SiteLocale $locale): string
    {
        return $this->render('site.type', $page, $locale, $page->path);
    }

    /**
     * @param  view-string  $view
     * @param  SitePath  $path  描画するページの場所（canonical・hreflang・言語の切り替えに使う）
     */
    private function render(string $view, IndexPage|ReleasePage|TypePage $page, SiteLocale $locale, SitePath $path): string
    {
        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale->value);

        try {
            return $this->views->make($view, [
                'page' => $page,
                'publicUrl' => $this->publicUrl,
                'locale' => $locale,
                'path' => $path,
                'siteUrl' => fn (?SitePath $target = null): string => ($target ?? SitePath::home())->url($locale),
                'siteNumber' => $locale->formatNumber(...),
            ])->render();
        } finally {
            $this->translator->setLocale($previous);
        }
    }
}
