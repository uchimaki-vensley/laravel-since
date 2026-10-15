<?php

namespace Tests\Feature;

use App\Application\Releases\ReleaseRepository;
use App\Application\Releases\ReleaseSourceStore;
use App\Application\Releases\ReleaseSummary;
use App\Infrastructure\Archives\UnsafeArchiveException;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Jobs\FetchReleaseSource;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Support\TarArchive;
use Tests\TestCase;

class FetchReleaseSourceTest extends TestCase
{
    use RefreshDatabase;

    private const string ARCHIVE_URL = 'https://codeload.github.com/laravel/framework/tar.gz/refs/tags/v13.3.0';

    private string $sources;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        $this->sources = sys_get_temp_dir().'/since-sources-'.bin2hex(random_bytes(8));
        config([
            'since.sources.path' => $this->sources,
            'since.github.token' => 'test-token',
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->sources);

        parent::tearDown();
    }

    public function test_downloads_and_extracts_the_release_source(): void
    {
        $release = $this->release();
        Http::fake([self::ARCHIVE_URL => Http::response(self::archive()->gzip())]);

        FetchReleaseSource::dispatch($release->id);

        $path = "{$this->sources}/laravel/framework/13.3.0";
        $this->assertSame($path, $this->store()->path($this->summary($release->id)));
        $this->assertSame('<?php // Str', file_get_contents("{$path}/src/Illuminate/Support/Str.php"));
        $this->assertSame(['laravel'], $this->entries($this->sources));

        // tarball は公開リポジトリから取るので、GitHub のトークンを送らない。
        Http::assertSent(fn (Request $request) => $request->url() === self::ARCHIVE_URL && ! $request->hasHeader('Authorization'));
    }

    public function test_does_not_download_a_release_that_is_already_extracted(): void
    {
        $release = $this->release();
        Http::fake([self::ARCHIVE_URL => Http::response(self::archive()->gzip())]);

        FetchReleaseSource::dispatch($release->id);
        FetchReleaseSource::dispatch($release->id);

        Http::assertSentCount(1);
    }

    public function test_ignores_a_release_that_no_longer_exists(): void
    {
        Http::fake();

        FetchReleaseSource::dispatch(999);

        Http::assertNothingSent();
    }

    public function test_rejects_a_malicious_archive_and_leaves_nothing_behind(): void
    {
        $release = $this->release();
        $archive = (new TarArchive)
            ->directory('framework-13.3.0/')
            ->file('framework-13.3.0/ok.php', '<?php')
            ->file('framework-13.3.0/../../../escaped.php', '<?php');
        Http::fake([self::ARCHIVE_URL => Http::response($archive->gzip())]);

        try {
            FetchReleaseSource::dispatch($release->id);
            $this->fail('The malicious archive was not rejected.');
        } catch (UnsafeArchiveException $exception) {
            $this->assertStringContainsString('Unsafe path', $exception->getMessage());
        }

        $this->assertFalse($this->store()->has($this->summary($release->id)));
        $this->assertSame([], $this->entries("{$this->sources}/.tmp"));
        $this->assertFileDoesNotExist(dirname($this->sources).'/escaped.php');
    }

    public function test_rejects_an_archive_larger_than_the_download_limit(): void
    {
        config(['since.sources.max_download_bytes' => 100]);
        $release = $this->release();
        Http::fake([self::ARCHIVE_URL => Http::response(str_repeat('x', 101))]);

        try {
            FetchReleaseSource::dispatch($release->id);
            $this->fail('The oversized archive was not rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The source archive exceeds 100 bytes.', $exception->getMessage());
        }

        $this->assertFalse($this->store()->has($this->summary($release->id)));
    }

    public function test_retries_transient_download_errors(): void
    {
        $release = $this->release();
        Http::fake([
            self::ARCHIVE_URL => Http::sequence()
                ->push('', 503)
                ->push(self::archive()->gzip()),
        ]);

        FetchReleaseSource::dispatch($release->id);

        $this->assertTrue($this->store()->has($this->summary($release->id)));
        Http::assertSentCount(2);
    }

    public function test_job_is_rate_limited_and_does_not_overlap_for_the_same_release(): void
    {
        $middleware = (new FetchReleaseSource(42))->middleware();

        $this->assertNotEmpty(array_filter($middleware, fn (object $m) => $m instanceof RateLimited));

        $overlap = array_values(array_filter($middleware, fn (object $m) => $m instanceof WithoutOverlapping));
        $this->assertCount(1, $overlap);
        $this->assertSame('release-source:42', $overlap[0]->key);
    }

    private function release(): Release
    {
        return Release::factory()->create(['version' => '13.3.0', 'tag' => 'v13.3.0', 'parsed_at' => null]);
    }

    private function summary(int $id): ReleaseSummary
    {
        $summary = $this->app->make(ReleaseRepository::class)->find($id);
        $this->assertNotNull($summary);

        return $summary;
    }

    private function store(): ReleaseSourceStore
    {
        return $this->app->make(ReleaseSourceStore::class);
    }

    private static function archive(): TarArchive
    {
        return (new TarArchive)
            ->globalPax()
            ->directory('framework-13.3.0/')
            ->directory('framework-13.3.0/src/Illuminate/Support/')
            ->file('framework-13.3.0/src/Illuminate/Support/Str.php', '<?php // Str');
    }

    /**
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        return is_dir($directory) ? array_values(array_diff(scandir($directory) ?: [], ['.', '..', '.tmp'])) : [];
    }
}
