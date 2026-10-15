<?php

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Archives\SafeTarGzExtractor;
use App\Infrastructure\Archives\UnsafeArchiveException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TarArchive;

class SafeTarGzExtractorTest extends TestCase
{
    private string $workspace;

    private string $destination;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/since-extractor-'.bin2hex(random_bytes(8));
        $this->destination = $this->workspace.'/out';
        mkdir($this->destination, 0755, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->workspace);

        parent::tearDown();
    }

    public function test_extracts_a_github_style_archive_without_the_top_level_directory(): void
    {
        $longName = str_repeat('a', 120).'.php';
        $archive = (new TarArchive)
            ->globalPax()
            ->directory('framework-13.3.0/')
            ->directory('framework-13.3.0/src/Illuminate/Support/')
            ->file('framework-13.3.0/src/Illuminate/Support/Str.php', '<?php // Str')
            ->file('framework-13.3.0/composer.json', '{}')
            ->entry('Builder.php', '0', '<?php // Builder', prefix: 'framework-13.3.0/src/Illuminate/Database/Query')
            ->pax('path', "framework-13.3.0/src/{$longName}")
            ->file('framework-13.3.0/src/truncated', '<?php // long')
            ->file('framework-13.3.0/empty.txt', '');

        $this->extract($archive);

        $this->assertSame('<?php // Str', file_get_contents("{$this->destination}/src/Illuminate/Support/Str.php"));
        $this->assertSame('{}', file_get_contents("{$this->destination}/composer.json"));
        $this->assertSame('<?php // Builder', file_get_contents("{$this->destination}/src/Illuminate/Database/Query/Builder.php"));
        $this->assertSame('<?php // long', file_get_contents("{$this->destination}/src/{$longName}"));
        $this->assertFileDoesNotExist("{$this->destination}/src/truncated");
        $this->assertSame('', file_get_contents("{$this->destination}/empty.txt"));
        $this->assertDirectoryDoesNotExist("{$this->destination}/framework-13.3.0");
    }

    public function test_extracts_files_larger_than_one_chunk(): void
    {
        $contents = random_bytes(200_000);

        $this->extract((new TarArchive)->directory('root/')->file('root/big.bin', $contents));

        $this->assertSame($contents, file_get_contents("{$this->destination}/big.bin"));
    }

    /**
     * @return array<string, array{TarArchive, string}>
     */
    public static function unsafeArchives(): array
    {
        return [
            'parent directory traversal' => [
                (new TarArchive)->directory('root/')->file('root/../../escaped.php', 'x'),
                'Unsafe path',
            ],
            'traversal hidden in prefix' => [
                (new TarArchive)->directory('root/')->entry('escaped.php', '0', 'x', prefix: 'root/../..'),
                'Unsafe path',
            ],
            'traversal in pax path' => [
                (new TarArchive)->directory('root/')->pax('path', 'root/../../escaped.php')->file('root/a.php', 'x'),
                'Unsafe path',
            ],
            'absolute path' => [
                (new TarArchive)->file('/tmp/escaped.php', 'x'),
                'Unsafe path',
            ],
            'windows drive path' => [
                (new TarArchive)->file('C:/escaped.php', 'x'),
                'Unsafe path',
            ],
            'backslash path' => [
                (new TarArchive)->directory('root/')->file('root\\..\\escaped.php', 'x'),
                'Unsafe path',
            ],
            'symbolic link' => [
                (new TarArchive)->directory('root/')->entry('root/link', '2', linkName: '/etc'),
                'Unsupported entry type "2"',
            ],
            'hard link' => [
                (new TarArchive)->directory('root/')->entry('root/link', '1', linkName: '/etc/passwd'),
                'Unsupported entry type "1"',
            ],
            'character device' => [
                (new TarArchive)->directory('root/')->entry('root/dev', '3'),
                'Unsupported entry type "3"',
            ],
            'fifo' => [
                (new TarArchive)->directory('root/')->entry('root/fifo', '6'),
                'Unsupported entry type "6"',
            ],
            'gnu long name' => [
                (new TarArchive)->directory('root/')->entry('././@LongLink', 'L', 'root/../../escaped.php'),
                'Unsupported entry type "L"',
            ],
            'pax link path' => [
                (new TarArchive)->directory('root/')->pax('linkpath', '/etc')->file('root/a.php', 'x'),
                'Unsupported pax header: linkpath',
            ],
            'pax size override' => [
                (new TarArchive)->directory('root/')->pax('size', '999999999')->file('root/a.php', 'x'),
                'Unsupported pax header: size',
            ],
            'second top-level directory' => [
                (new TarArchive)->directory('root/')->file('other/a.php', 'x'),
                'one top-level directory',
            ],
            'file at the top level' => [
                (new TarArchive)->file('a.php', 'x'),
                'inside the top-level directory',
            ],
            'duplicate file' => [
                (new TarArchive)->directory('root/')->file('root/a.php', 'x')->file('root/a.php', 'y'),
                'duplicate entry',
            ],
            'broken checksum' => [
                (new TarArchive)->raw(substr_replace(TarArchive::header('root/a.php', '0', 0), '0000000', 148, 7)),
                'broken header',
            ],
            'truncated data' => [
                (new TarArchive)->raw(TarArchive::header('root/a.php', '0', 1024).'short'),
                'ended unexpectedly',
            ],
            'not a tar archive' => [
                (new TarArchive)->raw(str_repeat('x', 512)),
                'Only ustar',
            ],
        ];
    }

    #[DataProvider('unsafeArchives')]
    public function test_rejects_unsafe_archives(TarArchive $archive, string $message): void
    {
        try {
            $this->extract($archive);
            $this->fail('The archive was not rejected.');
        } catch (UnsafeArchiveException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame(['archive.tar.gz', 'out'], array_values(array_diff(scandir($this->workspace) ?: [], ['.', '..'])));
        $this->assertFileDoesNotExist(dirname($this->workspace).'/escaped.php');
    }

    public function test_rejects_a_file_larger_than_the_limit(): void
    {
        $this->expectExceptionObject(new UnsafeArchiveException('A file in the archive is too large: 101 bytes.'));

        $this->extract((new TarArchive)->directory('root/')->file('root/a.php', str_repeat('x', 101)), maxFileBytes: 100);
    }

    public function test_stops_reading_when_the_extracted_size_exceeds_the_limit(): void
    {
        $archive = new TarArchive;
        $archive->directory('root/');

        for ($i = 0; $i < 10; $i++) {
            $archive->file("root/{$i}.txt", str_repeat("\0", 1000));
        }

        $this->expectExceptionObject(new UnsafeArchiveException('The extracted archive is too large.'));

        $this->extract($archive, maxTotalBytes: 8192);
    }

    public function test_rejects_too_many_entries(): void
    {
        $archive = (new TarArchive)->directory('root/')->file('root/a', '')->file('root/b', '');

        $this->expectExceptionObject(new UnsafeArchiveException('The archive has too many entries.'));

        $this->extract($archive, maxEntries: 2);
    }

    public function test_requires_an_empty_destination(): void
    {
        file_put_contents("{$this->destination}/existing", 'x');

        $this->expectExceptionObject(new UnsafeArchiveException('The destination must be an existing empty directory.'));

        $this->extract((new TarArchive)->directory('root/'));
    }

    public function test_applies_the_same_checks_to_an_uncompressed_tar(): void
    {
        $archive = "{$this->workspace}/archive.tar.gz";
        file_put_contents($archive, (new TarArchive)->directory('root/')->file('root/a.php', 'x')->tar());

        // zlib は gzip でない入力をそのまま読むので、tar として検証された内容だけが展開される。
        (new SafeTarGzExtractor(1024, 1024 * 1024, 100))->extract($archive, $this->destination);

        $this->assertSame('x', file_get_contents("{$this->destination}/a.php"));
    }

    private function extract(TarArchive $archive, int $maxFileBytes = 1024 * 1024, int $maxTotalBytes = 10 * 1024 * 1024, int $maxEntries = 100): void
    {
        $path = "{$this->workspace}/archive.tar.gz";
        file_put_contents($path, $archive->gzip());

        (new SafeTarGzExtractor($maxFileBytes, $maxTotalBytes, $maxEntries))->extract($path, $this->destination);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::remove($path.'/'.$entry);
        }

        rmdir($path);
    }
}
