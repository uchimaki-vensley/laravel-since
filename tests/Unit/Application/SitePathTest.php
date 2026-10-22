<?php

namespace Tests\Unit\Application;

use App\Application\Site\SiteLocale;
use App\Application\Site\SitePath;
use App\Domain\Releases\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SitePathTest extends TestCase
{
    public function test_maps_a_namespaced_type_to_nested_directories(): void
    {
        $this->assertSame('/ja/types/Illuminate/Support/Str/', SitePath::type('Illuminate\Support\Str')?->url(SiteLocale::Japanese));
        $this->assertSame('en/types/Global_Name1/index.html', SitePath::type('Global_Name1')?->file(SiteLocale::English));
    }

    public function test_builds_urls_and_files_with_the_locale_prefix(): void
    {
        $this->assertSame('/hu/', SitePath::home()->url(SiteLocale::Hungarian));
        $this->assertSame('hu/index.html', SitePath::home()->file(SiteLocale::Hungarian));
        $this->assertSame('/en/releases/13.3.0/', SitePath::release(Version::parse('13.3.0'))->url(SiteLocale::English));
        $this->assertSame('ja/releases/13.3.0/index.html', SitePath::release(Version::parse('13.3.0'))->file(SiteLocale::Japanese));
        $this->assertSame('/ja/types/Illuminate/Support/Str/#slug', SitePath::type('Illuminate\Support\Str')?->withFragment('slug')->url(SiteLocale::Japanese));
        $this->assertSame('/en/types/A/#a%20b', SitePath::type('A')?->withFragment('a b')->url(SiteLocale::English));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeNames(): array
    {
        return [
            'empty' => [''],
            'parent directory' => ['Illuminate\..\Evil'],
            'current directory' => ['Illuminate\.\Evil'],
            'slash' => ['Illuminate/../../Evil'],
            'empty segment' => ['Illuminate\\\\Evil'],
            'leading separator' => ['\Illuminate\Evil'],
            'leading digit' => ['Illuminate\1Evil'],
            'non-ascii' => ['Illuminate\Évil'],
            'control character' => ["Illuminate\\Evil\n"],
        ];
    }

    #[DataProvider('unsafeNames')]
    public function test_refuses_names_that_cannot_be_a_safe_path(string $fqcn): void
    {
        $this->assertNull(SitePath::type($fqcn));
    }
}
