<?php

namespace Tests\Unit\Domain;

use App\Domain\Releases\PackageName;
use App\Domain\Releases\ReleaseTag;
use App\Domain\Releases\Version;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReleaseValuesTest extends TestCase
{
    public function test_parses_a_stable_release_tag(): void
    {
        $tag = ReleaseTag::tryParse('v13.3.10');

        $this->assertNotNull($tag);
        $this->assertSame('v13.3.10', $tag->name);
        $this->assertSame('13.3.10', (string) $tag->version);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonStableTags(): array
    {
        return [
            'without v prefix' => ['13.3.0'],
            'prerelease' => ['v11.0.0-beta1'],
            'two parts' => ['v13.3'],
            'leading zero' => ['v13.03.0'],
            'traversal' => ['v13.3.0/../../x'],
            'trailing newline' => ["v13.3.0\n"],
            'branch name' => ['master'],
        ];
    }

    #[DataProvider('nonStableTags')]
    public function test_ignores_tags_that_are_not_stable_releases(string $name): void
    {
        $this->assertNull(ReleaseTag::tryParse($name));
    }

    public function test_compares_versions_numerically(): void
    {
        $this->assertTrue(Version::parse('11.10.0')->isAtLeast(Version::parse('11.9.9')));
        $this->assertTrue(Version::parse('11.0.0')->isAtLeast(Version::parse('11.0.0')));
        $this->assertFalse(Version::parse('10.48.29')->isAtLeast(Version::parse('11.0.0')));
        $this->assertSame(-1, Version::parse('12.0.0')->compare(Version::parse('12.0.1')));
    }

    public function test_rejects_an_invalid_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Version::parse('11.x');
    }

    public function test_accepts_composer_package_names(): void
    {
        $this->assertSame('laravel/framework', (string) new PackageName('laravel/framework'));
        $this->assertSame('larastan/larastan', (new PackageName('larastan/larastan'))->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPackageNames(): array
    {
        return [
            'no vendor' => ['framework'],
            'traversal' => ['laravel/../framework'],
            'dot segment' => ['../framework'],
            'extra segment' => ['laravel/framework/src'],
            'upper case' => ['Laravel/Framework'],
            'url' => ['https://example.com/x'],
        ];
    }

    #[DataProvider('invalidPackageNames')]
    public function test_rejects_invalid_package_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PackageName($name);
    }
}
