<?php

namespace Tests\Feature;

use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Jobs\FetchReleaseSource;
use App\Jobs\ParseReleaseSource;
use App\Jobs\RecordReleaseChanges;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class SyncCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string TAGS_URL = 'https://api.github.com/repos/laravel/framework/tags*';

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Sleep::fake();
        config(['since.github.token' => null]);
    }

    public function test_registers_stable_releases_since_the_minimum_version_across_pages(): void
    {
        $firstPage = array_map(static fn (int $patch) => "v12.0.{$patch}", range(0, 99));

        Http::fake([
            self::TAGS_URL => Http::sequence()
                ->push(self::tags($firstPage))
                ->push(self::tags(['v11.0.0', 'v11.0.0-beta1', 'v10.48.29', 'v13.3.0-RC', '13.0.0', 'v11.10.1'])),
        ]);

        $this->sync()->assertSuccessful();

        $versions = Release::query()->pluck('version')->all();
        $this->assertCount(102, $versions);
        $this->assertContains('11.0.0', $versions);
        $this->assertContains('11.10.1', $versions);
        $this->assertNotContains('10.48.29', $versions);
        $this->assertSame(
            ['package' => 'laravel/framework', 'tag' => 'v11.10.1'],
            Release::query()->where('version', '11.10.1')->firstOrFail()->only(['package', 'tag']),
        );

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/laravel/framework/tags?per_page=100&page=2');
        Http::assertSentCount(2);
    }

    public function test_queues_one_batch_with_a_fetch_parse_and_record_chain_per_unparsed_release(): void
    {
        Release::factory()->create(['version' => '11.0.0', 'tag' => 'v11.0.0', 'parsed_at' => now()]);
        Http::fake([self::TAGS_URL => self::tags(['v11.0.1', 'v11.0.0', 'v11.1.0'])]);

        $this->sync()
            ->expectsOutputToContain('Registered 2 new release(s).')
            ->expectsOutputToContain('Queued fetch, parse and change recording jobs for 2 release(s)')
            ->assertSuccessful();

        $expected = Release::query()->whereIn('version', ['11.0.1', '11.1.0'])->orderBy('version')->pluck('id')->all();

        Bus::assertBatched(function (PendingBatch $batch) use ($expected) {
            $chains = $batch->jobs->map(fn (mixed $chain) => is_array($chain) && count($chain) === 3
                && $chain[0] instanceof FetchReleaseSource && $chain[1] instanceof ParseReleaseSource
                && $chain[2] instanceof RecordReleaseChanges
                && $chain[0]->releaseId === $chain[1]->releaseId
                    ? $chain[0]->releaseId
                    : null)->all();

            return $batch->name === 'since:sync' && $batch->allowsFailures() && $chains === $expected;
        });
    }

    public function test_running_twice_does_not_register_releases_again(): void
    {
        Http::fake([self::TAGS_URL => self::tags(['v11.0.0', 'v11.0.1'])]);

        $this->sync()->expectsOutputToContain('Registered 2 new release(s).')->assertSuccessful();
        $this->sync()->expectsOutputToContain('Registered 0 new release(s).')->assertSuccessful();

        $this->assertSame(2, Release::query()->count());
    }

    public function test_queues_only_change_recording_when_every_release_is_parsed(): void
    {
        Release::factory()->create(['version' => '11.0.0', 'tag' => 'v11.0.0', 'parsed_at' => now()]);
        Http::fake([self::TAGS_URL => self::tags(['v11.0.0'])]);

        $this->sync()->expectsOutputToContain('No unparsed releases. Queued change recording.')->assertSuccessful();

        Bus::assertNothingBatched();
        Bus::assertDispatched(RecordReleaseChanges::class);
    }

    public function test_sends_the_github_token_only_when_configured(): void
    {
        Http::fake([self::TAGS_URL => Http::response([])]);

        $this->sync()->assertSuccessful();
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));

        config(['since.github.token' => 'test-token']);
        $this->sync()->assertSuccessful();
        Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['Bearer test-token']);
    }

    public function test_retries_transient_github_errors(): void
    {
        Http::fake([
            self::TAGS_URL => Http::sequence()
                ->push('', 502)
                ->push('', 429)
                ->push(self::tags(['v11.0.0'])),
        ]);

        $this->sync()->assertSuccessful();

        $this->assertSame(1, Release::query()->count());
        Http::assertSentCount(3);
    }

    public function test_fails_without_retrying_when_github_refuses_the_request(): void
    {
        Http::fake([self::TAGS_URL => Http::response(['message' => 'API rate limit exceeded'], 403)]);

        $this->expectException(RequestException::class);

        try {
            $this->sync()->run();
        } finally {
            Http::assertSentCount(1);
            $this->assertSame(0, Release::query()->count());
        }
    }

    private function sync(): PendingCommand
    {
        $command = $this->artisan('since:sync');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * @param  list<string>  $names
     * @return list<array{name: string}>
     */
    private static function tags(array $names): array
    {
        return array_map(static fn (string $name) => ['name' => $name], $names);
    }
}
