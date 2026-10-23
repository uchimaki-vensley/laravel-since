<?php

namespace Tests\Feature;

use App\Application\Site\SiteLocale;
use App\Application\Site\SitePath;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\SymbolChangeKind;
use App\Infrastructure\Persistence\Eloquent\Models\Method;
use App\Infrastructure\Persistence\Eloquent\Models\Parameter;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Symbol;
use App\Infrastructure\Persistence\Eloquent\Models\SymbolChange;
use App\Infrastructure\Persistence\Eloquent\Models\Type;
use App\Infrastructure\Site\SiteView;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Testing\PendingCommand;
use Illuminate\Translation\Translator;
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

    public function test_writes_the_search_release_and_type_pages_in_each_language(): void
    {
        $this->seedReleases();

        $this->build()
            ->expectsOutputToContain('Skipped a type whose name cannot be a page path: Illuminate\..\Evil')
            ->expectsOutputToContain('Built the site: 2 release(s), 2 type(s), 5 method(s).')
            ->assertSuccessful();

        $this->assertSame([
            '.since-site',
            '404.html',
            '_headers',
            'assets/favicon.svg',
            'assets/language.js',
            'assets/search.js',
            'assets/site.css',
            'en/index.html',
            'en/releases/13.2.0/index.html',
            'en/releases/13.3.0/index.html',
            'en/types/Illuminate/Database/Eloquent/Builder/index.html',
            'en/types/Illuminate/Old/Gone/index.html',
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
        $this->assertStringContainsString('<strong>どのリリースで追加され</strong>、<strong>いつシグネチャが変わったか</strong> を逆引きできます。', $index);
        $this->assertStringContainsString('対象は <code>laravel/framework</code> v13.2.0 〜 v13.3.0。', $index);
        $this->assertStringContainsString('<code>@internal</code> も含める', $index);
        $this->assertStringContainsString('<a href="/ja/releases/13.3.0/">v13.3.0</a>', $index);
        $this->assertStringContainsString('data-text-since=":version から"', $index);

        $builder = $this->page('ja/types/Illuminate/Database/Eloquent/Builder/index.html');
        $this->assertStringContainsString('<section class="member" id="incrementEach">', $builder);
        $this->assertStringContainsString('<a href="/ja/releases/13.3.0/">v13.3.0</a> から', $builder);
        $this->assertStringContainsString('<a href="/ja/releases/13.2.0/">v13.2.0</a> 以前から', $builder);
        $this->assertStringContainsString('incrementEach(array $columns, array $extra = []): int', $builder);
        $this->assertStringContainsString('@param array&lt;string, float|int|numeric-string&gt; $columns', $builder);
        $this->assertStringContainsString('static macroed(string|null $name = null)', $builder);
        $this->assertStringContainsString('<summary><code>@internal</code> のメソッド（1）</summary>', $builder);
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
        $this->assertStringContainsString('<span class="kind kind-signature_changed">シグネチャ変更</span>', $release);
        $this->assertStringContainsString('<code>Illuminate\..\Evil</code>', $release);
        $this->assertStringContainsString('<code>surprise</code>', $release);

        // 解析の起点のリリースは、全メソッドが「追加」になるので件数だけ出す。
        $baseline = $this->page('ja/releases/13.2.0/index.html');
        $this->assertStringContainsString('<span class="count">2</span>', $baseline);
        $this->assertStringContainsString('一覧は省略します。', $baseline);
        $this->assertStringNotContainsString('legacy', $baseline);

        // 英語のページは同じ構成で、文言とリンクが英語になる。
        $englishIndex = $this->page('en/index.html');
        $this->assertStringContainsString('Look up <strong>which release added them</strong> and <strong>when their signatures changed</strong>', $englishIndex);
        $this->assertStringContainsString('Covers <code>laravel/framework</code> v13.2.0 to v13.3.0.', $englishIndex);
        $this->assertStringContainsString('Include <code>@internal</code>', $englishIndex);
        $this->assertStringContainsString('<a href="/en/releases/13.3.0/">v13.3.0</a>', $englishIndex);

        $englishBuilder = $this->page('en/types/Illuminate/Database/Eloquent/Builder/index.html');
        $this->assertStringContainsString('since <a href="/en/releases/13.3.0/">v13.3.0</a>', $englishBuilder);
        $this->assertStringContainsString('since <a href="/en/releases/13.2.0/">v13.2.0</a> or earlier', $englishBuilder);
        $this->assertStringContainsString('<summary><code>@internal</code> methods (1)</summary>', $englishBuilder);
        $this->assertStringContainsString('Removed in v13.3.0', $this->page('en/types/Illuminate/Old/Gone/index.html'));

        $englishRelease = $this->page('en/releases/13.3.0/index.html');
        $this->assertStringContainsString('<a href="/en/types/Illuminate/Database/Eloquent/Builder/#incrementEach"><code>incrementEach</code></a>', $englishRelease);
        $this->assertStringContainsString('<span class="kind kind-signature_changed">Signature changed</span>', $englishRelease);
        $this->assertStringContainsString('<dt>Before</dt>', $englishRelease);

        foreach ($this->localizedPages() as $file) {
            // 翻訳のキーがそのまま出ていない。
            $this->assertDoesNotMatchRegularExpression('/\bsite\.[a-z_]+\.[a-z_]+/', $this->page($file), $file);
        }
    }

    public function test_each_page_links_to_itself_in_the_other_language(): void
    {
        $this->seedReleases();

        $this->build()->assertSuccessful();

        $builder = 'types/Illuminate/Database/Eloquent/Builder/';
        $japanese = $this->page("ja/{$builder}index.html");
        $english = $this->page("en/{$builder}index.html");

        $this->assertStringContainsString('<html lang="ja" data-locales="en ja">', $japanese);
        $this->assertStringContainsString('<html lang="en" data-locales="en ja">', $english);
        $this->assertStringContainsString("<a href=\"/en/{$builder}\" hreflang=\"en\" lang=\"en\" data-locale=\"en\">English</a>", $japanese);
        $this->assertStringContainsString("<a href=\"/ja/{$builder}\" hreflang=\"ja\" lang=\"ja\" data-locale=\"ja\">日本語</a>", $english);

        foreach ([$japanese, $english] as $html) {
            $this->assertStringContainsString("<link rel=\"alternate\" hreflang=\"en\" href=\"https://laravel-since.pages.dev/en/{$builder}\">", $html);
            $this->assertStringContainsString("<link rel=\"alternate\" hreflang=\"ja\" href=\"https://laravel-since.pages.dev/ja/{$builder}\">", $html);
            $this->assertStringContainsString("<link rel=\"alternate\" hreflang=\"x-default\" href=\"https://laravel-since.pages.dev/en/{$builder}\">", $html);
            $this->assertStringContainsString('<script src="/assets/language.js" defer></script>', $html);
        }

        $this->assertStringContainsString('<a class="brand" href="/ja/">', $japanese);
        $this->assertStringContainsString('<a class="brand" href="/en/">', $english);
    }

    public function test_pages_without_a_language_send_visitors_to_their_language(): void
    {
        $this->seedReleases();

        $this->build()->assertSuccessful();

        $entry = $this->page('index.html');
        $notFound = $this->page('404.html');

        foreach ([$entry, $notFound] as $html) {
            $this->assertStringContainsString('<html lang="en" data-locales="en ja" data-default-locale="en" data-redirect>', $html);
            // ページを描く前に移るよう、defer を付けずに読み込む。
            $this->assertStringContainsString('<script src="/assets/language.js"></script>', $html);
            $this->assertStringContainsString('<a href="/en/" hreflang="en" data-locale="en">View in English</a>', $html);
            $this->assertStringContainsString('<a href="/ja/" hreflang="ja" data-locale="ja">日本語で見る</a>', $html);
        }

        $this->assertStringContainsString('<link rel="alternate" hreflang="ja" href="https://laravel-since.pages.dev/ja/">', $entry);
        $this->assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://laravel-since.pages.dev/">', $entry);
        $this->assertStringNotContainsString('noindex', $entry);

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $notFound);
        $this->assertStringContainsString('ページが見つかりません。', $notFound);
        $this->assertStringContainsString('Page not found.', $notFound);
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
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc="\/(?!\/))[^>]*>/', $html, $file);
            $this->assertDoesNotMatchRegularExpression('/<(?:script|img)[^>]+src="(?!\/(?!\/))/', $html, $file);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $html, $file);
            $this->assertStringNotContainsString(' style=', $html, $file);

            // <link> は自サイトのファイルか、自サイトの公開 URL（言語ごとの代替リンク）だけを指す。
            preg_match_all('/<link[^>]+href="([^"]*)"/', $html, $links);

            foreach ($links[1] as $href) {
                $this->assertMatchesRegularExpression('#\A(?:/(?!/)|https://laravel-since\.pages\.dev/)#', $href, $file);
            }
        }

        // 言語ごとのページのサイト内リンクは、すべて言語の付いた URL か静的ファイルを指す。
        foreach ($this->localizedPages() as $file) {
            preg_match_all('/<a[^>]+href="(\/[^"]*)"/', $this->page($file), $links);
            $this->assertNotEmpty($links[1], $file);

            foreach ($links[1] as $href) {
                $this->assertMatchesRegularExpression('#\A/(?:en|ja)/#', $href, $file);
            }
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
        $this->assertFileExists("{$this->dist}/ja/index.html");
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
        $this->assertStringContainsString('No releases have been parsed yet.', $this->page('en/index.html'));
        $this->assertSame(['latest' => null, 'types' => [], 'members' => []], json_decode($this->page('search.json'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_translations_have_the_same_keys_and_placeholders_in_every_language(): void
    {
        $translator = $this->app->make(Translator::class);
        $lines = [];

        foreach (SiteLocale::cases() as $locale) {
            $site = $translator->get('site', [], $locale->value, false);
            $this->assertIsArray($site, $locale->value);
            $lines[$locale->value] = Arr::dot($site);
        }

        $expected = array_shift($lines);

        foreach ($lines as $locale => $actual) {
            $this->assertSame(array_keys($expected), array_keys($actual), $locale);

            foreach ($expected as $key => $line) {
                $this->assertIsString($line, $key);
                $this->assertIsString($actual[$key], "{$locale}: {$key}");
                $this->assertSame(self::placeholders($line), self::placeholders($actual[$key]), "{$locale}: {$key}");
            }
        }
    }

    public function test_every_change_kind_has_a_label_in_every_language(): void
    {
        $translator = $this->app->make(Translator::class);

        foreach (SiteLocale::cases() as $locale) {
            foreach (SymbolChangeKind::cases() as $kind) {
                $this->assertTrue($translator->hasForLocale("site.kind.{$kind->value}", $locale->value), "{$locale->value}: {$kind->value}");
            }
        }
    }

    public function test_a_missing_translation_stops_the_build_instead_of_falling_back(): void
    {
        $translator = $this->app->make(Translator::class);
        $translator->addLines(['site.only_in_english' => 'Only in English'], 'en');
        $view = new SiteView($translator, 'https://laravel-since.pages.dev', SiteLocale::Ja, SitePath::home());

        $this->assertSame('Only in English', $view->t('site.only_in_english', [], SiteLocale::En));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing translation for ja: site.only_in_english');
        $view->t('site.only_in_english');
    }

    public function test_a_site_path_cannot_be_printed_without_a_language(): void
    {
        $this->expectExceptionMessage('App\Application\Site\SitePath given');

        Blade::render('<a href="{{ $path }}">', ['path' => SitePath::home()]);
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $line): array
    {
        preg_match_all('/:([a-z_]+)/', $line, $matches);
        $names = $matches[1];
        sort($names);

        return $names;
    }

    /**
     * @return list<string>
     */
    private function localizedPages(): array
    {
        return array_values(array_filter($this->files(), static fn (string $file) => preg_match('#\A(?:en|ja)/.*\.html\z#', $file) === 1));
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
