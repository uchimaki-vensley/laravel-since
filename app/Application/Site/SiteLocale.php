<?php

namespace App\Application\Site;

/**
 * サイトを出力する言語。ページは言語ごとに `/{言語}/...` に置く。
 */
enum SiteLocale: string
{
    case En = 'en';
    case Ja = 'ja';

    /**
     * ブラウザの言語がどれにも合わないときに表示する言語。
     */
    public static function default(): self
    {
        return self::En;
    }
}
