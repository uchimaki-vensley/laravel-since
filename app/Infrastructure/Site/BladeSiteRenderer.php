<?php

namespace App\Infrastructure\Site;

use App\Application\Site\Pages\IndexPage;
use App\Application\Site\Pages\ReleasePage;
use App\Application\Site\Pages\TypePage;
use App\Application\Site\SiteLocale;
use App\Application\Site\SitePaths;
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
        return $this->render('site.index', $page, $locale, '');
    }

    public function release(ReleasePage $page, SiteLocale $locale): string
    {
        return $this->render('site.release', $page, $locale, 'releases/'.$page->release->version);
    }

    public function type(TypePage $page, SiteLocale $locale): string
    {
        return $this->render('site.type', $page, $locale, SitePaths::typeDirectory($page->fqcn) ?? throw new \InvalidArgumentException('Unsafe type name.'));
    }

    /** @param view-string $view */
    private function render(string $view, IndexPage|ReleasePage|TypePage $page, SiteLocale $locale, string $directory): string
    {
        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale->value);

        try {
            return $this->views->make($view, [
                'page' => $page,
                'publicUrl' => $this->publicUrl,
                'locale' => $locale,
                'directory' => $directory,
                'siteUrl' => $locale->url(...),
                'siteNumber' => fn (int $number): string => number_format($number, 0, '.', match ($locale) {
                    SiteLocale::Hungarian => "\u{00a0}",
                    default => ',',
                }),
            ])->render();
        } finally {
            $this->translator->setLocale($previous);
        }
    }
}
