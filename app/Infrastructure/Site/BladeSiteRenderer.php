<?php

namespace App\Infrastructure\Site;

use App\Application\Site\Pages\IndexPage;
use App\Application\Site\Pages\ReleasePage;
use App\Application\Site\Pages\TypePage;
use App\Application\Site\SiteLocale;
use App\Application\Site\SitePath;
use App\Application\Site\SiteRenderer;
use Illuminate\Contracts\View\Factory;
use Illuminate\Translation\Translator;
use InvalidArgumentException;

/**
 * Blade（`resources/views/site`）でページを HTML にする。テンプレートでは `{{ }}` だけを使い、値を必ずエスケープする。
 *
 * 文言は `lang/{言語}/site.php` から取る（SiteView）。
 */
final readonly class BladeSiteRenderer implements SiteRenderer
{
    /**
     * @param  string  $siteUrl  サイトの公開 URL（例: `https://laravel-since.pages.dev`）
     */
    public function __construct(
        private Factory $views,
        private Translator $translator,
        private string $siteUrl,
    ) {
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]+)?\z#', $siteUrl) !== 1) {
            throw new InvalidArgumentException("The site URL must be an origin without a path: {$siteUrl}");
        }
    }

    public function entry(): string
    {
        return $this->render('site.entry', SiteLocale::default(), SitePath::home(), ['notFound' => false]);
    }

    public function notFound(): string
    {
        return $this->render('site.entry', SiteLocale::default(), SitePath::home(), ['notFound' => true]);
    }

    public function index(IndexPage $page, SiteLocale $locale): string
    {
        return $this->render('site.index', $locale, SitePath::home(), ['page' => $page]);
    }

    public function release(ReleasePage $page, SiteLocale $locale): string
    {
        return $this->render('site.release', $locale, $page->release->path, ['page' => $page]);
    }

    public function type(TypePage $page, SiteLocale $locale): string
    {
        return $this->render('site.type', $locale, $page->path, ['page' => $page]);
    }

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    private function render(string $view, SiteLocale $locale, SitePath $path, array $data): string
    {
        return $this->views->make($view, [
            ...$data,
            'site' => new SiteView($this->translator, $this->siteUrl, $locale, $path),
        ])->render();
    }
}
