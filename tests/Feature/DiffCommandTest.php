<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Jobs\ParseReleaseSource;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class DiffCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $sources;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sources = sys_get_temp_dir().'/since-sources-'.bin2hex(random_bytes(8));
        config(['since.sources.path' => $this->sources]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->sources);

        parent::tearDown();
    }

    public function test_shows_the_methods_added_between_two_releases(): void
    {
        $this->parsedRelease('13.2.0');
        $this->parsedRelease('13.3.0');

        $this->diff('13.2.0', 'v13.3.0')
            ->expectsOutputToContain('13.2.0 → 13.3.0: 2 added, 0 removed, 0 signature changed, 0 deprecated.')
            ->expectsOutput('Illuminate\Database\Eloquent\Builder')
            ->expectsOutput('  + decrementEach(array $columns, array $extra = []) [@param array<string, float|int|numeric-string> $columns, @param array<string, mixed> $extra, @return int]')
            ->expectsOutput('  + incrementEach(array $columns, array $extra = []) [@param array<string, float|int|numeric-string> $columns, @param array<string, mixed> $extra, @return int]')
            ->assertSuccessful();
    }

    public function test_shows_removed_methods_in_the_reverse_direction(): void
    {
        $this->parsedRelease('13.2.0');
        $this->parsedRelease('13.3.0');

        $this->diff('13.3.0', '13.2.0')
            ->expectsOutputToContain('13.3.0 → 13.2.0: 0 added, 2 removed, 0 signature changed, 0 deprecated.')
            ->expectsOutputToContain('  - incrementEach(array $columns, array $extra = [])')
            ->assertSuccessful();
    }

    public function test_fails_for_a_release_that_cannot_be_compared(): void
    {
        $this->parsedRelease('13.2.0');
        Release::factory()->create(['version' => '13.3.0', 'tag' => 'v13.3.0', 'parsed_at' => null]);

        $this->diff('13.2.0', '13.3.0')->expectsOutputToContain('laravel/framework 13.3.0 has not been parsed.')->assertFailed();
        $this->diff('13.2.0', '13.4.0')->expectsOutputToContain('laravel/framework 13.4.0 is not registered.')->assertFailed();
        $this->diff('13.2', '13.3.0')->expectsOutputToContain('Invalid version: 13.2')->assertFailed();
    }

    private function diff(string $from, string $to): PendingCommand
    {
        $command = $this->artisan('since:diff', ['from' => $from, 'to' => $to]);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function parsedRelease(string $version): void
    {
        (new Filesystem)->copyDirectory(__DIR__."/../Fixtures/Releases/{$version}", "{$this->sources}/laravel/framework/{$version}");
        $release = Release::factory()->create(['version' => $version, 'tag' => "v{$version}", 'parsed_at' => null]);

        ParseReleaseSource::dispatch($release->id);
    }
}
