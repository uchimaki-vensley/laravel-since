<?php

namespace App\Application\Site;

use App\Application\Site\Pages\IndexPage;
use App\Application\Site\Pages\ReleasePage;
use App\Application\Site\Pages\TypePage;

/**
 * ページの内容を HTML にする。値は必ずエスケープして出力する。
 */
interface SiteRenderer
{
    /**
     * 言語の付かないトップ（`/`）。ブラウザの言語に合うトップへ移る。
     */
    public function entry(): string;

    /**
     * ページがない URL に返す 404。言語の付かない URL（公開済みの古い URL など）は、ブラウザの言語に合う同じページへ移る。
     */
    public function notFound(): string;

    public function index(IndexPage $page, SiteLocale $locale): string;

    public function release(ReleasePage $page, SiteLocale $locale): string;

    public function type(TypePage $page, SiteLocale $locale): string;
}
