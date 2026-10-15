<?php

namespace Tests\Feature;

use App\Domain\Enums\SymbolChangeKind;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Symbol;
use App\Infrastructure\Persistence\Eloquent\Models\SymbolChange;
use App\Jobs\ParseReleaseSource;
use App\Jobs\RecordReleaseChanges;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class RecordReleaseChangesTest extends TestCase
{
    use RefreshDatabase;

    private const string BUILDER = 'Illuminate\Database\Eloquent\Builder';

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

    public function test_records_increment_each_as_added_in_13_3_0(): void
    {
        $base = $this->parsedRelease('13.2.0');
        $next = $this->parsedRelease('13.3.0');

        RecordReleaseChanges::dispatch();

        // 最初のリリースより前は解析していないので、最初のリリースには変化を記録せずシンボルだけを作る。
        $this->assertSame(0, $base->symbolChanges()->count());
        $this->assertEqualsCanonicalizing(
            ['decrement', 'decrementEach', 'increment', 'incrementEach'],
            Symbol::query()->where('fqcn', self::BUILDER)->pluck('member')->all(),
        );

        $this->assertSame([
            ['decrementEach', SymbolChangeKind::Added],
            ['incrementEach', SymbolChangeKind::Added],
        ], $this->changes($next));

        $added = SymbolChange::query()->whereRelation('symbol', 'member', 'incrementEach')->firstOrFail();
        $this->assertNull($added->before);
        $this->assertSame([
            'signature' => 'incrementEach(array $columns, array $extra = []) [@param array<string, float|int|numeric-string> $columns, @param array<string, mixed> $extra, @return int]',
            'name' => 'incrementEach',
            'source' => 'declared',
            'is_static' => false,
            'return_type' => null,
            'docblock_return_type' => 'int',
            'parameters' => [
                ['name' => 'columns', 'type' => 'array', 'default_value' => null, 'is_variadic' => false, 'by_ref' => false, 'docblock_type' => 'array<string, float|int|numeric-string>'],
                ['name' => 'extra', 'type' => 'array', 'default_value' => '[]', 'is_variadic' => false, 'by_ref' => false, 'docblock_type' => 'array<string, mixed>'],
            ],
            'is_internal' => false,
            'is_deprecated' => false,
        ], $added->after);
    }

    public function test_records_signature_changes_and_deprecations_with_both_signatures(): void
    {
        $this->parsedRelease('13.2.0');
        $next = $this->parsedRelease('13.3.0', function (string $source) {
            return str_replace(
                ['public function increment($column, $amount = 1, array $extra = [])', '    public function decrement('],
                ['public function increment($column, float|int $amount = 1, array $extra = [])', "    #[\\Deprecated]\n    public function decrement("],
                $source,
            );
        });

        RecordReleaseChanges::dispatch();

        $this->assertSame([
            ['decrement', SymbolChangeKind::Deprecated],
            ['decrementEach', SymbolChangeKind::Added],
            ['increment', SymbolChangeKind::SignatureChanged],
            ['incrementEach', SymbolChangeKind::Added],
        ], $this->changes($next));

        $changed = SymbolChange::query()->where('kind', SymbolChangeKind::SignatureChanged)->firstOrFail();
        $tags = ' [@param string|\\Illuminate\\Contracts\\Database\\Query\\Expression $column, @param float|int $amount, @param array $extra, @return int]';
        $this->assertSame('increment($column, $amount = 1, array $extra = [])'.$tags, $changed->before['signature'] ?? null);
        $this->assertSame('increment($column, float|int $amount = 1, array $extra = [])'.$tags, $changed->after['signature'] ?? null);
    }

    public function test_waits_for_the_previous_release_and_records_after_it_is_parsed(): void
    {
        $base = $this->release('13.2.0');
        $next = $this->parsedRelease('13.3.0');

        RecordReleaseChanges::dispatch();

        $this->assertSame(0, SymbolChange::query()->count());
        $this->assertNull($next->refresh()->changes_basis);

        ParseReleaseSource::dispatch($base->id);
        RecordReleaseChanges::dispatch();

        $this->assertSame([
            ['decrementEach', SymbolChangeKind::Added],
            ['incrementEach', SymbolChangeKind::Added],
        ], $this->changes($next));
    }

    public function test_recording_again_changes_nothing_until_a_snapshot_is_replaced(): void
    {
        $this->parsedRelease('13.2.0');
        $next = $this->parsedRelease('13.3.0');

        RecordReleaseChanges::dispatch();
        $ids = SymbolChange::query()->orderBy('id')->pluck('id')->all();
        $symbols = Symbol::query()->count();

        RecordReleaseChanges::dispatch();
        $this->assertSame($ids, SymbolChange::query()->orderBy('id')->pluck('id')->all());

        // 再解析でスナップショットの版が進むと、そのリリースの変更履歴を記録し直す（重複はしない）。
        ParseReleaseSource::dispatch($next->id);
        RecordReleaseChanges::dispatch();

        $this->assertNotSame($ids, SymbolChange::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, SymbolChange::query()->count());
        $this->assertSame($symbols, Symbol::query()->count());
    }

    public function test_reuses_a_symbol_whose_name_differs_only_in_case(): void
    {
        $this->parsedRelease('13.2.0');
        $next = $this->parsedRelease('13.3.0', fn (string $source) => str_replace('function increment(', 'function INCREMENT(', $source));

        RecordReleaseChanges::dispatch();

        $this->assertSame(1, Symbol::query()->where('fqcn', self::BUILDER)->whereIn('member', ['increment', 'INCREMENT'])->count());
        $this->assertSame([
            ['decrementEach', SymbolChangeKind::Added],
            ['incrementEach', SymbolChangeKind::Added],
        ], $this->changes($next));
    }

    public function test_job_does_not_overlap_with_another_recording(): void
    {
        $overlap = array_values(array_filter(
            (new RecordReleaseChanges)->middleware(),
            fn (object $middleware) => $middleware instanceof WithoutOverlapping,
        ));

        $this->assertCount(1, $overlap);
        $this->assertSame('release-changes', $overlap[0]->key);
        $this->assertSame(30, $overlap[0]->releaseAfter);
    }

    /**
     * @return list<array{string, SymbolChangeKind}>
     */
    private function changes(Release $release): array
    {
        return array_values($release->symbolChanges()
            ->with('symbol')
            ->get()
            ->map(fn (SymbolChange $change) => [$change->symbol->member ?? '', $change->kind])
            ->sortBy(fn (array $change) => $change[0].':'.$change[1]->value)
            ->values()
            ->all());
    }

    /**
     * @param  (callable(string): string)|null  $edit  フィクスチャの Builder.php を書き換える
     */
    private function parsedRelease(string $version, ?callable $edit = null): Release
    {
        $release = $this->release($version, $edit);
        ParseReleaseSource::dispatch($release->id);

        return $release->refresh();
    }

    /**
     * @param  (callable(string): string)|null  $edit
     */
    private function release(string $version, ?callable $edit = null): Release
    {
        $path = "{$this->sources}/laravel/framework/{$version}";
        (new Filesystem)->copyDirectory(__DIR__."/../Fixtures/Releases/{$version}", $path);

        if ($edit !== null) {
            $file = "{$path}/src/Illuminate/Database/Eloquent/Builder.php";
            file_put_contents($file, $edit((string) file_get_contents($file)));
        }

        return Release::factory()->create(['version' => $version, 'tag' => "v{$version}", 'parsed_at' => null]);
    }
}
