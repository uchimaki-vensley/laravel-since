<?php

namespace Tests\Unit\Application;

use App\Application\Site\SiteLocale;
use PHPUnit\Framework\TestCase;

class SiteLocaleTest extends TestCase
{
    public function test_formats_numbers_with_the_cldr_grouping_of_each_locale(): void
    {
        $this->assertSame('999', SiteLocale::English->formatNumber(999));
        $this->assertSame('1,646', SiteLocale::English->formatNumber(1646));
        $this->assertSame('1,646', SiteLocale::Japanese->formatNumber(1646));
        $this->assertSame('13,068', SiteLocale::Japanese->formatNumber(13068));

        // ハンガリー語は 4 桁を区切らない（ブラウザの toLocaleString('hu') と同じ）。
        $this->assertSame('1646', SiteLocale::Hungarian->formatNumber(1646));
        $this->assertSame("13\u{00a0}068", SiteLocale::Hungarian->formatNumber(13068));
        $this->assertSame("1\u{00a0}234\u{00a0}567", SiteLocale::Hungarian->formatNumber(1234567));
    }
}
