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
    public function index(IndexPage $page): string;

    public function release(ReleasePage $page): string;

    public function type(TypePage $page): string;
}
