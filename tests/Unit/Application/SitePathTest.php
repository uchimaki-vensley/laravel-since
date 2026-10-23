<?php

namespace Tests\Unit\Application;

use App\Application\Site\SiteLocale;
use App\Application\Site\SitePath;
use App\Domain\Releases\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SitePathTest extends TestCase
{
    public function test_puts_every_page_under_its_language(): void
    {
        $this->assertSame('/ja/', SitePath::home()->url(SiteLocale::Ja));
        $this->assertSame('en/index.html', SitePath::home()->file(SiteLocale::En));
        $this->assertSame('/en/releases/13.3.0/', SitePath::release(Version::parse('13.3.0'))->url(SiteLocale::En));
        $this->assertSame('ja/releases/13.3.0/index.html', SitePath::release(Version::parse('13.3.0'))->file(SiteLocale::Ja));
    }

    public function test_maps_a_namespaced_type_to_nested_directories(): void
    {
        $str = SitePath::type('Illuminate\Support\Str');

        $this->assertNotNull($str);
        $this->assertSame('/ja/types/Illuminate/Support/Str/', $str->url(SiteLocale::Ja));
        $this->assertSame('en/types/Illuminate/Support/Str/index.html', $str->file(SiteLocale::En));
        $this->assertSame('/en/types/Global_Name1/', SitePath::type('Global_Name1')?->url(SiteLocale::En));
    }

    public function test_points_to_a_method_on_the_page(): void
    {
        $str = SitePath::type('Illuminate\Support\Str');

        $this->assertNotNull($str);
        $this->assertSame('/en/types/Illuminate/Support/Str/#slug', $str->withFragment('slug')->url(SiteLocale::En));
        $this->assertSame('en/types/Illuminate/Support/Str/index.html', $str->withFragment('slug')->file(SiteLocale::En));
        $this->assertSame('/en/#a%20b', SitePath::home()->withFragment('a b')->url(SiteLocale::En));
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
