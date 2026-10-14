<?php

namespace Tests\Feature;

use App\Application\Releases\GitHubReleaseSource;
use App\Infrastructure\ReleaseSource\SafeTarballExtractor;
use Carbon\CarbonInterval;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class SyncReleasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_supported_stable_tags_and_batches_source_jobs(): void
    {
        Http::fake([
            'api.github.com/repos/laravel/framework/tags?*' => Http::response([
                ['name' => 'v13.0.0'],
                ['name' => 'v11.0.0'],
                ['name' => 'v10.48.0'],
                ['name' => 'v13.1.0-beta1'],
            ]),
        ]);
        Bus::fake();

        $this->assertSame(0, Artisan::call('since:sync'));

        $this->assertDatabaseHas('releases', [
            'package' => 'laravel/framework',
            'version' => '11.0.0',
            'tag' => 'v11.0.0',
        ]);
        $this->assertDatabaseHas('releases', ['version' => '13.0.0']);
        $this->assertDatabaseMissing('releases', ['version' => '10.48.0']);
        $this->assertDatabaseMissing('releases', ['version' => '13.1.0-beta1']);
        Bus::assertBatched(static fn (PendingBatch $batch): bool => $batch->jobs->count() === 2);
    }

    public function test_it_waits_for_the_github_hourly_limit_to_reset(): void
    {
        config(['since.github_token' => null]);
        Http::fakeSequence()
            ->push(['message' => 'API rate limit exceeded'], 403, [
                'x-ratelimit-remaining' => '0',
                'x-ratelimit-reset' => (string) (time() + 90),
            ])
            ->push([['name' => 'v13.0.0']]);
        Sleep::fake();

        try {
            $this->assertSame(
                [['tag' => 'v13.0.0', 'released_at' => null]],
                app(GitHubReleaseSource::class)->stableTags('laravel/framework'),
            );
            Sleep::assertSlept(static fn (CarbonInterval $duration): bool => $duration->totalSeconds >= 89 && $duration->totalSeconds <= 92);
            Http::assertSentCount(2);
        } finally {
            Sleep::fake(false);
        }
    }

    public function test_it_obeys_github_retry_after_on_a_rate_limit_response(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'secondary rate limit'], 429, ['retry-after' => '7'])
            ->push([['name' => 'v13.0.0']]);
        Sleep::fake();

        try {
            $this->assertCount(1, app(GitHubReleaseSource::class)->stableTags('laravel/framework'));
            Sleep::assertSlept(static fn (CarbonInterval $duration): bool => $duration->totalSeconds === 7.0);
            Http::assertSentCount(2);
        } finally {
            Sleep::fake(false);
        }
    }

    public function test_it_does_not_retry_an_unrelated_github_forbidden_response(): void
    {
        Http::fakeSequence()->push(['message' => 'Resource not accessible by integration'], 403);
        Sleep::fake();

        try {
            $this->expectException(RequestException::class);
            app(GitHubReleaseSource::class)->stableTags('laravel/framework');
        } finally {
            Sleep::assertNeverSlept();
            Http::assertSentCount(1);
            Sleep::fake(false);
        }
    }

    public function test_it_rejects_tar_path_traversal(): void
    {
        $base = storage_path('framework/testing/'.uniqid('archive-', true));
        $archive = $base.'.tar.gz';
        $destination = $base.'-out';
        if (! is_dir(dirname($archive))) {
            mkdir(dirname($archive), 0700, true);
        }
        file_put_contents($archive, gzencode($this->tarEntry('../escape.txt', 'malicious')));

        $rejected = false;
        try {
            (new SafeTarballExtractor)->extract($archive, $destination);
        } catch (RuntimeException) {
            $rejected = true;
        } finally {
            @unlink($archive);
        }

        $this->assertTrue($rejected, 'Expected the archive path traversal to be rejected.');
        $this->assertDirectoryDoesNotExist($destination);
        $this->assertFileDoesNotExist(dirname($destination).'/escape.txt');
    }

    public function test_it_rejects_tar_symbolic_links(): void
    {
        $base = storage_path('framework/testing/'.uniqid('archive-', true));
        $archive = $base.'.tar.gz';
        $destination = $base.'-out';
        if (! is_dir(dirname($archive))) {
            mkdir(dirname($archive), 0700, true);
        }
        file_put_contents($archive, gzencode($this->tarEntry('root/link', '', '2', '../../escape.txt')));

        $rejected = false;
        try {
            (new SafeTarballExtractor)->extract($archive, $destination);
        } catch (RuntimeException) {
            $rejected = true;
        } finally {
            @unlink($archive);
        }

        $this->assertTrue($rejected, 'Expected the symbolic link to be rejected.');
        $this->assertDirectoryDoesNotExist($destination);
        $this->assertFileDoesNotExist(dirname($destination).'/escape.txt');
    }

    public function test_it_extracts_a_valid_tarball(): void
    {
        $base = storage_path('framework/testing/'.uniqid('archive-', true));
        $archive = $base.'.tar.gz';
        $destination = $base.'-out';
        $contents = '<?php return 42;';
        if (! is_dir(dirname($archive))) {
            mkdir(dirname($archive), 0700, true);
        }
        file_put_contents($archive, gzencode($this->tarEntry('root/src/Sample.php', $contents)));

        try {
            (new SafeTarballExtractor)->extract($archive, $destination);
            $this->assertSame($contents, file_get_contents($destination.'/src/Sample.php'));
        } finally {
            @unlink($archive);
            File::deleteDirectory($destination);
        }
    }

    public function test_it_counts_pax_metadata_against_the_archive_size_limit(): void
    {
        $base = storage_path('framework/testing/'.uniqid('archive-', true));
        $archive = $base.'.tar';
        $destination = $base.'-out';
        $metadata = '15 path=root/a'."\n";
        config(['since.max_archive_file_bytes' => 20, 'since.max_archive_bytes' => 20]);
        if (! is_dir(dirname($archive))) {
            mkdir(dirname($archive), 0700, true);
        }
        file_put_contents(
            $archive,
            $this->tarEntry('PaxHeaders.0/a', $metadata, 'x', '', false)
                .$this->tarEntry('PaxHeaders.0/b', $metadata, 'x', '', false)
                .$this->tarEntry('root/a', 'source'),
        );

        $rejected = false;
        try {
            (new SafeTarballExtractor)->extract($archive, $destination);
        } catch (RuntimeException) {
            $rejected = true;
        } finally {
            @unlink($archive);
        }

        $this->assertTrue($rejected, 'Expected aggregate PAX metadata size to be rejected.');
        $this->assertDirectoryDoesNotExist($destination);
    }

    private function tarEntry(
        string $name,
        string $contents,
        string $type = '0',
        string $linkName = '',
        bool $endArchive = true,
    ): string {
        $header = str_pad($name, 100, "\0")
            .sprintf("%07o\0", 0644)
            .sprintf("%07o\0", 0)
            .sprintf("%07o\0", 0)
            .sprintf("%011o\0", strlen($contents))
            .sprintf("%011o\0", 0)
            .str_repeat(' ', 8)
            .$type
            .str_pad($linkName, 100, "\0")
            ."ustar\0"
            .'00'
            .str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
        $paddedContents = str_pad($contents, (int) (ceil(strlen($contents) / 512) * 512), "\0");

        return $header.$paddedContents.($endArchive ? str_repeat("\0", 1024) : '');
    }
}
