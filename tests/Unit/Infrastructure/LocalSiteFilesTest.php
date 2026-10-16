<?php

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Site\LocalSiteFiles;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LocalSiteFilesTest extends TestCase
{
    private string $workspace;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/since-site-files-'.bin2hex(random_bytes(8));
        $this->root = "{$this->workspace}/site";
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_writes_files_in_nested_directories(): void
    {
        $files = new LocalSiteFiles($this->root);

        $files->write('types/Illuminate/Support/Str/index.html', '<p>Str</p>');
        $files->write('_headers', 'headers');
        $files->write('.since-site', '');

        $this->assertSame('<p>Str</p>', file_get_contents("{$this->root}/types/Illuminate/Support/Str/index.html"));
        $this->assertSame('headers', file_get_contents("{$this->root}/_headers"));
        $this->assertFileExists("{$this->root}/.since-site");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'empty' => [''],
            'parent directory' => ['../escaped.html'],
            'parent directory in the middle' => ['types/../../escaped.html'],
            'current directory' => ['./index.html'],
            'absolute' => ['/tmp/escaped.html'],
            'empty segment' => ['types//index.html'],
            'trailing slash' => ['types/'],
            'hidden file' => ['.htaccess'],
            'backslash' => ['types\..\escaped.html'],
            'null byte' => ["index.html\0.txt"],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_refuses_paths_outside_of_the_site(string $path): void
    {
        try {
            (new LocalSiteFiles($this->root))->write($path, 'x');
            $this->fail('The path was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringStartsWith('Unsafe site path:', $exception->getMessage());
        }

        $this->assertSame(['site'], array_values(array_diff(scandir($this->workspace) ?: [], ['.', '..'])));
        $this->assertSame([], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
    }

    public function test_does_not_overwrite_a_written_file(): void
    {
        $files = new LocalSiteFiles($this->root);
        $files->write('index.html', 'first');

        try {
            $files->write('index.html', 'second');
            $this->fail('The file was overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }

        $this->assertSame('first', file_get_contents("{$this->root}/index.html"));
    }
}
