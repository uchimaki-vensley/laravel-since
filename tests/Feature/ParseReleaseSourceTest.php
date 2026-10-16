<?php

namespace Tests\Feature;

use App\Domain\Enums\MethodSource;
use App\Domain\Enums\TypeKind;
use App\Infrastructure\Persistence\Eloquent\Models\Method;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Type;
use App\Jobs\ParseReleaseSource;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Tests\TestCase;

class ParseReleaseSourceTest extends TestCase
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

    public function test_stores_the_public_api_of_the_release_and_marks_it_parsed(): void
    {
        $release = $this->releaseWithFixtureSource();

        ParseReleaseSource::dispatch($release->id);

        $this->assertNotNull($release->refresh()->parsed_at);
        $this->assertSame(5, $release->types()->count());

        $widget = Type::query()->where('fqcn', 'Illuminate\Fixture\Widget')->firstOrFail();
        $this->assertSame(TypeKind::ClassType, $widget->kind);
        $this->assertSame('src/Illuminate/Fixture/Widget.php', $widget->file_path);
        $this->assertFalse($widget->is_internal);
        $this->assertSame(13, $widget->methods()->count());
        // @method と同名の public メソッドは宣言側の 1 行だけ、protected と同名の @method は docblock 側を保存する。
        $this->assertSame(MethodSource::Declared, $widget->methods()->where('name', 'render')->sole()->source);
        $this->assertSame(MethodSource::Docblock, $widget->methods()->where('name', 'hidden')->sole()->source);

        $configure = $widget->methods()->where('name', 'configure')->firstOrFail();
        $this->assertSame(MethodSource::Declared, $configure->source);
        $this->assertSame('static', $configure->return_type);
        $this->assertSame('$this', $configure->docblock_return_type);
        $this->assertSame(64, strlen($configure->signature_hash));
        $this->assertSame(
            [
                ['position' => 1, 'name' => 'callback', 'type' => '?Closure', 'default_value' => 'null', 'is_variadic' => false, 'by_ref' => false, 'docblock_type' => '(Closure(static): void)|null'],
                ['position' => 4, 'name' => 'tags', 'type' => 'string', 'default_value' => null, 'is_variadic' => true, 'by_ref' => false, 'docblock_type' => 'non-empty-string'],
            ],
            $configure->parameters()->whereIn('position', [1, 4])->orderBy('position')->get()
                ->map(fn ($parameter) => $parameter->only(['position', 'name', 'type', 'default_value', 'is_variadic', 'by_ref', 'docblock_type']))
                ->all(),
        );

        $make = $widget->methods()->where('name', 'make')->firstOrFail();
        $this->assertSame(MethodSource::Docblock, $make->source);
        $this->assertTrue($make->is_static);
        $this->assertTrue($widget->methods()->where('name', 'internalHelper')->firstOrFail()->is_internal);
        $this->assertTrue($widget->methods()->where('name', 'legacy')->firstOrFail()->is_deprecated);
        $this->assertTrue(Type::query()->where('fqcn', 'Illuminate\Fixture\Conditional')->firstOrFail()->is_internal);
    }

    public function test_parsing_again_replaces_the_snapshot(): void
    {
        $release = $this->releaseWithFixtureSource();
        $other = Release::factory()->create();
        $otherType = Type::factory()->for($other)->create();

        ParseReleaseSource::dispatch($release->id);
        $methods = Method::query()->count();

        ParseReleaseSource::dispatch($release->id);

        $this->assertSame(5, $release->types()->count());
        $this->assertSame($methods, Method::query()->count());
        $this->assertTrue($otherType->exists());
        $this->assertSame(1, $other->types()->count());
    }

    public function test_fails_without_changes_when_the_source_has_not_been_fetched(): void
    {
        $release = Release::factory()->create(['version' => '13.3.0', 'tag' => 'v13.3.0', 'parsed_at' => null]);

        try {
            ParseReleaseSource::dispatch($release->id);
            $this->fail('Parsing a missing source did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The source of v13.3.0 has not been fetched.', $exception->getMessage());
        }

        $this->assertNull($release->refresh()->parsed_at);
        $this->assertSame(0, $release->types()->count());
    }

    public function test_keeps_the_previous_snapshot_when_parsing_fails(): void
    {
        $release = $this->releaseWithFixtureSource();
        ParseReleaseSource::dispatch($release->id);
        $parsedAt = $release->refresh()->parsed_at;

        file_put_contents($this->sourcePath().'/src/Illuminate/Fixture/Broken.php', '<?php class {');

        try {
            ParseReleaseSource::dispatch($release->id);
            $this->fail('The syntax error was not reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('src/Illuminate/Fixture/Broken.php', $exception->getMessage());
        }

        $this->assertSame(5, $release->types()->count());
        $this->assertEquals($parsedAt, $release->refresh()->parsed_at);
    }

    public function test_ignores_a_release_that_no_longer_exists(): void
    {
        ParseReleaseSource::dispatch(999);

        $this->assertSame(0, Type::query()->count());
    }

    public function test_job_does_not_overlap_for_the_same_release(): void
    {
        $overlap = array_values(array_filter(
            (new ParseReleaseSource(42))->middleware(),
            fn (object $middleware) => $middleware instanceof WithoutOverlapping,
        ));

        $this->assertCount(1, $overlap);
        $this->assertSame('release-parse:42', $overlap[0]->key);
    }

    private function releaseWithFixtureSource(): Release
    {
        (new Filesystem)->copyDirectory(__DIR__.'/../Fixtures/PhpSource', $this->sourcePath());

        return Release::factory()->create(['version' => '13.3.0', 'tag' => 'v13.3.0', 'parsed_at' => null]);
    }

    private function sourcePath(): string
    {
        return "{$this->sources}/laravel/framework/13.3.0";
    }
}
