<?php

namespace Tests\Unit\Application;

use App\Application\Site\SitePaths;
use App\Domain\Releases\Version;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SitePathsTest extends TestCase
{
    public function test_maps_a_namespaced_type_to_nested_directories(): void
    {
        $this->assertSame('types/Illuminate/Support/Str', SitePaths::typeDirectory('Illuminate\Support\Str'));
        $this->assertSame('types/Global_Name1', SitePaths::typeDirectory('Global_Name1'));
        $this->assertSame('releases/13.3.0', SitePaths::releaseDirectory(Version::parse('13.3.0')));
        $this->assertSame('/types/Illuminate/Support/Str/', SitePaths::url('types/Illuminate/Support/Str'));
        $this->assertSame('releases/13.3.0/index.html', SitePaths::page('releases/13.3.0'));
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
        $this->assertNull(SitePaths::typeDirectory($fqcn));
    }
}
