<?php

namespace App\Infrastructure\Site;

use App\Application\Site\Pages\IndexPage;
use App\Application\Site\Pages\ReleasePage;
use App\Application\Site\Pages\TypePage;
use App\Application\Site\SiteRenderer;
use Illuminate\Contracts\View\Factory;

/**
 * Blade（`resources/views/site`）でページを HTML にする。テンプレートでは `{{ }}` だけを使い、値を必ずエスケープする。
 */
final readonly class BladeSiteRenderer implements SiteRenderer
{
    public function __construct(
        private Factory $views,
    ) {}

    public function index(IndexPage $page): string
    {
        return $this->views->make('site.index', ['page' => $page])->render();
    }

    public function release(ReleasePage $page): string
    {
        return $this->views->make('site.release', ['page' => $page])->render();
    }

    public function type(TypePage $page): string
    {
        return $this->views->make('site.type', ['page' => $page])->render();
    }
}
