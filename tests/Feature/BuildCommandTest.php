<?php

namespace Tests\Feature;

use App\Application\Site\Pages\IndexPage;
use App\Application\Site\SiteLocale;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\SymbolChangeKind;
use App\Infrastructure\Persistence\Eloquent\Models\Method;
use App\Infrastructure\Persistence\Eloquent\Models\Parameter;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Symbol;
use App\Infrastructure\Persistence\Eloquent\Models\SymbolChange;
use App\Infrastructure\Persistence\Eloquent\Models\Type;
use App\Infrastructure\Site\BladeSiteRenderer;
use Illuminate\Contracts\View\Factory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\TestCase;

class BuildCommandTest extends TestCase
{
    use RefreshDatabase;

    private const string BUILDER = 'Illuminate\Database\Eloquent\Builder';

    private string $workspace;

    private string $dist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/since-site-'.bin2hex(random_bytes(8));
        $this->dist = "{$this->workspace}/dist";
        mkdir($this->workspace);
        config(['since.site.path' => $this->dist]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_writes_the_search_release_and_type_pages(): void
    {
        $this->seedReleases();

        $this->build()
            ->expectsOutputToContain('Skipped a type whose name cannot be a page path: Illuminate\..\Evil')
            ->expectsOutputToContain('Built the site: 2 release(s), 2 type(s), 5 method(s).')
            ->assertSuccessful();

        $this->assertSame([
            '.since-site',
            '_headers',
            '_redirects',
            'assets/favicon.svg',
            'assets/language.js',
            'assets/search.js',
            'assets/site.css',
            'en/index.html',
            'en/releases/13.2.0/index.html',
            'en/releases/13.3.0/index.html',
            'en/types/Illuminate/Database/Eloquent/Builder/index.html',
            'en/types/Illuminate/Old/Gone/index.html',
            'hu/index.html',
            'hu/releases/13.2.0/index.html',
            'hu/releases/13.3.0/index.html',
            'hu/types/Illuminate/Database/Eloquent/Builder/index.html',
            'hu/types/Illuminate/Old/Gone/index.html',
            'index.html',
            'ja/index.html',
            'ja/releases/13.2.0/index.html',
            'ja/releases/13.3.0/index.html',
            'ja/types/Illuminate/Database/Eloquent/Builder/index.html',
            'ja/types/Illuminate/Old/Gone/index.html',
            'search.json',
        ], $this->files());

        $index = $this->page('ja/index.html');
        $this->assertStringContainsString('<script src="/assets/search.js" defer></script>', $index);
        $this->assertStringContainsString('v13.2.0 〜 v13.3.0', $index);
        $this->assertStringContainsString('<a href="/ja/releases/13.3.0/">v13.3.0</a>', $index);

        $builder = $this->page('ja/types/Illuminate/Database/Eloquent/Builder/index.html');
        $this->assertStringContainsString('<section class="member" id="incrementEach">', $builder);
        $this->assertStringContainsString('<a href="/ja/releases/13.3.0/">v13.3.0 から</a>', $builder);
        $this->assertStringContainsString('<a href="/ja/releases/13.2.0/">v13.2.0 以前から</a>', $builder);
        $this->assertStringContainsString('incrementEach(array $columns, array $extra = []): int', $builder);
        $this->assertStringContainsString('@param array&lt;string, float|int|numeric-string&gt; $columns', $builder);
        $this->assertStringContainsString('static macroed(string|null $name = null)', $builder);
        $this->assertStringContainsString('<summary>@internal のメソッド（1）</summary>', $builder);
        // 値はすべてエスケープして出力する。
        $this->assertStringContainsString('$label = &#039;&lt;script&gt;alert(1)&lt;/script&gt;&#039;', $builder);
        $this->assertStringNotContainsString('<script>alert(1)', $builder);

        $gone = $this->page('ja/types/Illuminate/Old/Gone/index.html');
        $this->assertStringContainsString('この型は最新のリリース（v13.3.0）にはありません。', $gone);
        $this->assertStringContainsString('v13.3.0 で削除', $gone);

        $release = $this->page('ja/releases/13.3.0/index.html');
        $this->assertStringContainsString('<a href="/ja/types/Illuminate/Database/Eloquent/Builder/#incrementEach"><code>incrementEach</code></a>', $release);
        $this->assertStringContainsString('<dd><code>where(string $column): static</code></dd>', $release);
        $this->assertStringContainsString('<dd><code>where(string $column, mixed $value = null): static</code></dd>', $release);
        $this->assertStringContainsString('<a rel="prev" href="/ja/releases/13.2.0/">', $release);
        $this->assertStringContainsString('<code>Illuminate\..\Evil</code>', $release);
        $this->assertStringContainsString('<code>surprise</code>', $release);

        // 解析の起点のリリースは、全メソッドが「追加」になるので件数だけ出す。
        $baseline = $this->page('ja/releases/13.2.0/index.html');
        $this->assertStringContainsString('<span class="count">2</span>', $baseline);
        $this->assertStringContainsString('一覧は省略します。', $baseline);
        $this->assertStringNotContainsString('legacy', $baseline);
    }

    public function test_writes_security_headers_and_no_inline_or_external_scripts(): void
    {
        $this->seedReleases();

        $this->build()->assertSuccessful();

        $headers = $this->page('_headers');
        $this->assertStringStartsWith("/*\n", $headers);
        $this->assertStringContainsString("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self';", $headers);
        $this->assertStringContainsString('X-Content-Type-Options: nosniff', $headers);
        $this->assertStringContainsString('Referrer-Policy: strict-origin-when-cross-origin', $headers);
        $this->assertStringContainsString('X-Frame-Options: DENY', $headers);

        foreach (array_filter($this->files(), static fn (string $file) => str_ends_with($file, '.html')) as $file) {
            $html = $this->page($file);
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc="\/)[^>]*>/', $html, $file);
            $this->assertDoesNotMatchRegularExpression('/<(?:script|img)[^>]+src="(?!\/)/', $html, $file);
            $this->assertDoesNotMatchRegularExpression('/<link(?=[^>]*rel="(?:stylesheet|icon)")[^>]*href="(?!\/)/', $html, $file);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $html, $file);
            $this->assertStringNotContainsString(' style=', $html, $file);
        }
    }

    public function test_writes_a_compact_search_index(): void
    {
        $this->seedReleases();

        $this->build()->assertSuccessful();

        $this->assertSame([
            'latest' => '13.3.0',
            'types' => [[self::BUILDER, 0], ['Illuminate\Old\Gone', 4]],
            'members' => [
                [0, 'incrementEach', '13.3.0', 0],
                [0, 'macroed', null, 8],
                [0, 'where', '13.2.0', 16],
                [0, 'internalThing', null, 1],
                [1, 'legacy', '13.2.0', 4 | 16],
            ],
        ], json_decode($this->page('search.json'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_replaces_the_previous_output(): void
    {
        $this->seedReleases();
        $this->build()->assertSuccessful();
        file_put_contents("{$this->dist}/stale.html", 'old');

        $this->build()->assertSuccessful();

        $this->assertFileDoesNotExist("{$this->dist}/stale.html");
        $this->assertFileExists("{$this->dist}/index.html");
        $this->assertSame(['dist'], array_values(array_diff(scandir($this->workspace) ?: [], ['.', '..'])));
    }

    public function test_refuses_to_replace_a_directory_it_did_not_write(): void
    {
        mkdir($this->dist);
        file_put_contents("{$this->dist}/keep.txt", 'mine');

        try {
            $this->build()->run();
            $this->fail('The directory was replaced.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('it is not a site output of since:build', $exception->getMessage());
        }

        $this->assertSame('mine', file_get_contents("{$this->dist}/keep.txt"));
        $this->assertSame(['dist'], array_values(array_diff(scandir($this->workspace) ?: [], ['.', '..'])));
    }

    public function test_builds_an_empty_site_before_any_release_is_parsed(): void
    {
        Release::factory()->create(['version' => '13.0.0', 'tag' => 'v13.0.0', 'parsed_at' => null]);

        $this->build()
            ->expectsOutputToContain('Built the site: 0 release(s), 0 type(s), 0 method(s).')
            ->assertSuccessful();

        $this->assertStringContainsString('まだ解析済みのリリースはありません。', $this->page('ja/index.html'));
        $this->assertSame(['latest' => null, 'types' => [], 'members' => []], json_decode($this->page('search.json'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_builds_all_languages_with_local_links_metadata_and_search_messages(): void
    {
        $this->seedReleases();
        config(['since.site.url' => 'https://example.test/']);
        app('translator')->setLocale('fr');
        $this->build()->assertSuccessful();
        $this->assertSame('fr', app('translator')->getLocale());

        foreach (['en' => 'Search', 'ja' => '検索', 'hu' => 'Keresés'] as $locale => $title) {
            $index = $this->page("{$locale}/index.html");
            $this->assertStringContainsString('<html lang="'.$locale.'">', $index);
            $this->assertStringContainsString('<title>'.$title.' · laravel-since</title>', $index);
            $this->assertStringContainsString('href="/'.$locale.'/releases/13.3.0/"', $index);
            $this->assertStringContainsString('data-base="/'.$locale.'/"', $index);
            $this->assertStringContainsString('data-search-error="'.__('site.search_error', [], $locale).'"', $index);
            $this->assertStringNotContainsString('site.', strip_tags($index));

            foreach (['', 'releases/13.3.0/', 'types/Illuminate/Database/Eloquent/Builder/'] as $path) {
                $html = $this->page("{$locale}/{$path}index.html");
                $this->assertStringContainsString('rel="canonical" href="https://example.test/'.$locale.'/'.$path.'"', $html);
                foreach (['en', 'ja', 'hu'] as $alternate) {
                    $this->assertStringContainsString('hreflang="'.$alternate.'" href="https://example.test/'.$alternate.'/'.$path.'"', $html);
                    $this->assertStringContainsString('data-language href="/'.$alternate.'/'.$path.'"', $html);
                }
                $this->assertStringContainsString('hreflang="x-default" href="https://example.test/en/'.$path.'"', $html);
                // Every site link stays in the selected language except explicit language switches.
                $this->assertDoesNotMatchRegularExpression('~href="/(?:types|releases)/~', $html);
            }
        }

        $hungarian = $this->page('hu/types/Illuminate/Database/Eloquent/Builder/index.html');
        $this->assertStringContainsString('A v13.2.0 verzió óta vagy korábbról', $hungarian);
        $this->assertStringContainsString('A v13.3.0 verzió óta', $hungarian);
        $this->assertStringContainsString('Szignatúra módosítva', $hungarian);
        $this->assertStringContainsString('Since v13.2.0 or earlier', $this->page('en/types/Illuminate/Database/Eloquent/Builder/index.html'));
        $this->assertStringContainsString('<html lang="en">', $this->page('index.html'));
        $this->assertSame("/ /en/ 301\n/types/* /ja/types/:splat 301\n/releases/* /ja/releases/:splat 301\n", $this->page('_redirects'));
    }

    public function test_translations_have_matching_keys_and_placeholders_and_english_fallback(): void
    {
        $translator = app('translator');
        $english = $translator->get('site', [], 'en');
        $this->assertIsArray($english);

        foreach (['ja', 'hu'] as $locale) {
            $messages = $translator->get('site', [], $locale);
            $this->assertIsArray($messages);
            $this->assertSame(array_keys($english), array_keys($messages));
            foreach ($english as $key => $text) {
                $this->assertIsString($text);
                $this->assertIsString($messages[$key]);
                preg_match_all('/:[a-z_]+/', $text, $expected);
                preg_match_all('/:[a-z_]+/', $messages[$key], $actual);
                $this->assertEqualsCanonicalizing($expected[0], $actual[0], $locale.'.'.$key);
            }
        }

        $translator->setFallback('en');
        $this->assertSame('Search', $translator->get('site.search', [], 'fr'));
    }

    public function test_restores_the_translation_locale_if_rendering_fails(): void
    {
        $translator = app('translator');
        $translator->setLocale('fr');
        $views = $this->createMock(Factory::class);
        $views->expects($this->once())->method('make')->willThrowException(new RuntimeException('Rendering failed.'));
        $renderer = new BladeSiteRenderer($views, $translator, 'https://example.test');

        try {
            $renderer->index(new IndexPage(null, null, 0, 0, []), SiteLocale::Hungarian);
            $this->fail('Rendering should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rendering failed.', $exception->getMessage());
        }

        $this->assertSame('fr', $translator->getLocale());
    }

    private function seedReleases(): void
    {
        $previous = Release::factory()->create(['version' => '13.2.0', 'tag' => 'v13.2.0', 'changes_basis' => 'baseline:1']);
        $latest = Release::factory()->create(['version' => '13.3.0', 'tag' => 'v13.3.0', 'changes_basis' => "{$previous->id}@1:1"]);
        // 未解析のリリースと、解析済みでも変更履歴をまだ記録していないリリースは載せない。
        Release::factory()->create(['version' => '13.4.0', 'tag' => 'v13.4.0', 'parsed_at' => null]);
        Release::factory()->create(['version' => '13.5.0', 'tag' => 'v13.5.0', 'changes_basis' => null]);

        Type::factory()->for($previous)->create(['fqcn' => 'Illuminate\Old\Gone']);

        $builder = Type::factory()->for($latest)->create(['fqcn' => self::BUILDER, 'file_path' => 'src/Illuminate/Database/Eloquent/Builder.php']);
        $incrementEach = Method::factory()->for($builder)->create(['name' => 'incrementEach', 'return_type' => 'int', 'docblock_return_type' => null]);
        Parameter::factory()->for($incrementEach)->create(['position' => 0, 'name' => 'columns', 'type' => 'array', 'docblock_type' => 'array<string, float|int|numeric-string>']);
        Parameter::factory()->for($incrementEach)->create(['position' => 1, 'name' => 'extra', 'type' => 'array', 'default_value' => '[]', 'docblock_type' => null]);

        $where = Method::factory()->for($builder)->create(['name' => 'where', 'return_type' => 'static', 'docblock_return_type' => null]);
        Parameter::factory()->for($where)->create(['position' => 0, 'name' => 'column', 'docblock_type' => null]);
        Parameter::factory()->for($where)->create(['position' => 1, 'name' => 'value', 'type' => 'mixed', 'default_value' => 'null', 'docblock_type' => null]);
        Parameter::factory()->for($where)->create(['position' => 2, 'name' => 'label', 'default_value' => "'<script>alert(1)</script>'", 'docblock_type' => null]);

        $macroed = Method::factory()->for($builder)->create(['name' => 'macroed', 'is_static' => true, 'source' => MethodSource::Docblock, 'return_type' => null, 'docblock_return_type' => null]);
        Parameter::factory()->for($macroed)->create(['position' => 0, 'name' => 'name', 'type' => null, 'default_value' => 'null', 'docblock_type' => 'string|null']);

        Method::factory()->for($builder)->create(['name' => 'internalThing', 'is_internal' => true]);

        Type::factory()->for($latest)->create(['fqcn' => 'Illuminate\..\Evil']);

        $this->change(self::BUILDER, 'where', $previous, SymbolChangeKind::Added, null, 'where(string $column): static');
        $this->change(self::BUILDER, 'where', $latest, SymbolChangeKind::SignatureChanged, 'where(string $column): static', 'where(string $column, mixed $value = null): static');
        $this->change(self::BUILDER, 'incrementEach', $latest, SymbolChangeKind::Added, null, 'incrementEach(array $columns, array $extra = []): int');
        $this->change('Illuminate\Old\Gone', 'legacy', $previous, SymbolChangeKind::Added, null, 'legacy(): void');
        $this->change('Illuminate\Old\Gone', 'legacy', $latest, SymbolChangeKind::Removed, 'legacy(): void', null);
        $this->change('Illuminate\..\Evil', 'surprise', $latest, SymbolChangeKind::Added, null, 'surprise(): void');
    }

    private function change(string $fqcn, string $member, Release $release, SymbolChangeKind $kind, ?string $before, ?string $after): void
    {
        SymbolChange::factory()
            ->for(Symbol::query()->firstOrCreate(['fqcn' => $fqcn, 'member' => $member]))
            ->for($release)
            ->create([
                'kind' => $kind,
                'before' => $before === null ? null : ['signature' => $before],
                'after' => $after === null ? null : ['signature' => $after],
            ]);
    }

    private function build(): PendingCommand
    {
        $command = $this->artisan('since:build');
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->dist, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), strlen($this->dist) + 1);
        }

        sort($files);

        return $files;
    }

    private function page(string $path): string
    {
        return (string) file_get_contents("{$this->dist}/{$path}");
    }
}
