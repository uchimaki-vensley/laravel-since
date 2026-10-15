<?php

namespace App\Application\Site;

use App\Application\Releases\ReleaseSummary;
use App\Application\Site\Pages\ChangeItem;
use App\Application\Site\Pages\ChangeSection;
use App\Application\Site\Pages\HistoryEntry;
use App\Application\Site\Pages\IndexPage;
use App\Application\Site\Pages\MemberView;
use App\Application\Site\Pages\ReleaseLink;
use App\Application\Site\Pages\ReleasePage;
use App\Application\Site\Pages\TypePage;
use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Enums\MethodSource;
use App\Domain\Enums\SymbolChangeKind;
use App\Domain\Releases\PackageName;

/**
 * 最新のリリースのスナップショットと全リリースの変更履歴から、静的サイトを作る。
 *
 * - トップ（検索）: `index.html` と、ブラウザ内で絞り込む `search.json`
 * - リリースごとの変更一覧: `releases/{version}/index.html`
 * - 型ごとのメソッド一覧: `types/{名前空間を / で区切った型名}/index.html`
 */
final readonly class SiteBuilderService implements SiteBuilder
{
    /** search.json の印（ビットの組み合わせ） */
    public const int FLAG_INTERNAL = 1;

    public const int FLAG_DEPRECATED = 2;

    public const int FLAG_REMOVED = 4;

    public const int FLAG_DOCBLOCK = 8;

    public const int FLAG_SINCE_OR_EARLIER = 16;

    /** 同じリリースの中で変化を並べる順 */
    private const array KIND_ORDER = [
        SymbolChangeKind::Added->value => 0,
        SymbolChangeKind::SignatureChanged->value => 1,
        SymbolChangeKind::Deprecated->value => 2,
        SymbolChangeKind::Removed->value => 3,
    ];

    public function __construct(
        private SiteCatalog $catalog,
        private SiteRenderer $renderer,
        private SiteOutput $output,
        private PackageName $package,
    ) {}

    public function build(): SiteBuildResult
    {
        $releases = $this->catalog->parsedReleases($this->package);
        $latest = $releases === [] ? null : $releases[array_key_last($releases)];
        $oldest = $releases[0] ?? null;

        $snapshot = [];

        foreach ($latest === null ? [] : $this->catalog->types($latest) as $type) {
            $snapshot[$type->fqcn] = $type;
        }

        $changes = $this->sortedChanges($releases);
        $changesByType = [];
        $changesByVersion = [];

        foreach ($changes as $change) {
            $changesByType[$change->fqcn][] = $change;
            $changesByVersion[(string) $change->version][] = $change;
        }

        $directories = [];
        $skipped = [];

        foreach (array_keys($snapshot + $changesByType) as $fqcn) {
            $fqcn = (string) $fqcn;
            $directory = SitePaths::typeDirectory($fqcn);

            if ($directory === null) {
                $skipped[] = $fqcn;
            } else {
                $directories[$fqcn] = $directory;
            }
        }

        ksort($directories, SORT_STRING);
        sort($skipped, SORT_STRING);

        $typePages = [];

        foreach ($directories as $fqcn => $directory) {
            $typePages[$fqcn] = $this->typePage(
                $fqcn,
                $snapshot[$fqcn] ?? null,
                $changesByType[$fqcn] ?? [],
                $latest,
                $oldest,
            );
        }

        $links = $this->releaseLinks($releases, $changes);
        $memberCount = array_sum(array_map(static fn (TypePage $page) => count($page->members) + count($page->internalMembers), $typePages));

        $this->output->replace(function (SiteFiles $files) use ($releases, $links, $changesByVersion, $directories, $typePages, $latest, $oldest, $memberCount) {
            $files->write('index.html', $this->renderer->index(new IndexPage(
                $latest === null ? null : (string) $latest->tag->version,
                $oldest === null ? null : (string) $oldest->tag->version,
                count($typePages),
                $memberCount,
                array_reverse(array_values($links)),
            )));

            $files->write('search.json', self::searchIndex($typePages, $latest));

            foreach ($releases as $index => $release) {
                $version = (string) $release->tag->version;
                $previous = $releases[$index - 1] ?? null;
                $next = $releases[$index + 1] ?? null;

                $files->write(SitePaths::page(SitePaths::releaseDirectory($release->tag->version)), $this->renderer->release(new ReleasePage(
                    $links[$version],
                    $previous === null ? null : $links[(string) $previous->tag->version],
                    $next === null ? null : $links[(string) $next->tag->version],
                    // 解析の起点のリリースでは、その時点のメソッドがすべて「追加」になるので一覧を省く。
                    $this->sections($changesByVersion[$version] ?? [], $directories, $typePages, omitAdded: $index === 0),
                )));
            }

            foreach ($typePages as $fqcn => $page) {
                $files->write(SitePaths::page($directories[$fqcn]), $this->renderer->type($page));
            }
        });

        return new SiteBuildResult(count($releases), count($typePages), $memberCount, $skipped);
    }

    /**
     * 解析済みのリリースの変化だけを、バージョン・種類の順に並べる。
     *
     * @param  list<ReleaseSummary>  $releases
     * @return list<SymbolChangeEntry>
     */
    private function sortedChanges(array $releases): array
    {
        $versions = array_flip(array_map(static fn (ReleaseSummary $release) => (string) $release->tag->version, $releases));
        $changes = array_values(array_filter(
            $this->catalog->changes($this->package),
            static fn (SymbolChangeEntry $change) => isset($versions[(string) $change->version]),
        ));

        usort($changes, static fn (SymbolChangeEntry $a, SymbolChangeEntry $b) => $a->version->compare($b->version)
            ?: self::KIND_ORDER[$a->kind->value] <=> self::KIND_ORDER[$b->kind->value]);

        return $changes;
    }

    /**
     * @param  list<SymbolChangeEntry>  $changes  この型の変化（古い順）
     */
    private function typePage(string $fqcn, ?TypeDeclaration $type, array $changes, ?ReleaseSummary $latest, ?ReleaseSummary $oldest): TypePage
    {
        /** @var array<string, array{method: ?MethodDeclaration, name: string, changes: list<SymbolChangeEntry>}> $members */
        $members = [];

        foreach ($type === null ? [] : $type->methods as $method) {
            $members[strtolower($method->name)] = ['method' => $method, 'name' => $method->name, 'changes' => []];
        }

        foreach ($changes as $change) {
            $key = strtolower($change->member);
            $members[$key] ??= ['method' => null, 'name' => $change->member, 'changes' => []];
            $members[$key]['changes'][] = $change;
        }

        $views = array_map(
            fn (array $member) => $this->memberView($member['name'], $member['method'], $member['changes'], $oldest),
            array_values($members),
        );

        usort($views, static fn (MemberView $a, MemberView $b) => strcasecmp($a->name, $b->name));

        return new TypePage(
            $fqcn,
            $type?->kind,
            $type?->filePath,
            $type->isInternal ?? false,
            $type->isDeprecated ?? false,
            $latest === null ? null : (string) $latest->tag->version,
            array_values(array_filter($views, static fn (MemberView $view) => ! $view->isInternal)),
            array_values(array_filter($views, static fn (MemberView $view) => $view->isInternal)),
        );
    }

    /**
     * @param  list<SymbolChangeEntry>  $changes  このメンバーの変化（古い順）
     */
    private function memberView(string $name, ?MethodDeclaration $method, array $changes, ?ReleaseSummary $oldest): MemberView
    {
        $since = null;
        $removedIn = null;
        $deprecated = false;

        // 削除のあとに再び追加された場合は、最後の追加を「いつから」とする。
        foreach ($changes as $change) {
            if ($change->kind === SymbolChangeKind::Added) {
                $since = $change->version;
                $removedIn = null;
            } elseif ($change->kind === SymbolChangeKind::Removed) {
                $removedIn = $change->version;
            } elseif ($change->kind === SymbolChangeKind::Deprecated) {
                $deprecated = true;
            }
        }

        return new MemberView(
            $name,
            $method === null ? null : SignatureFormatter::signature($method),
            $method === null ? [] : SignatureFormatter::docblockLines($method),
            $since === null ? null : (string) $since,
            $since === null ? null : SitePaths::url(SitePaths::releaseDirectory($since)),
            $since !== null && $oldest !== null && $since->compare($oldest->tag->version) === 0,
            $method->isStatic ?? false,
            $method?->source === MethodSource::Docblock,
            $method->isInternal ?? false,
            $method === null ? $deprecated : $method->isDeprecated,
            $method === null && $removedIn !== null ? (string) $removedIn : null,
            array_map(static fn (SymbolChangeEntry $change) => new HistoryEntry(
                (string) $change->version,
                SitePaths::url(SitePaths::releaseDirectory($change->version)),
                $change->kind,
                $change->before,
                $change->after,
            ), $changes),
        );
    }

    /**
     * @param  list<ReleaseSummary>  $releases
     * @param  list<SymbolChangeEntry>  $changes
     * @return array<string, ReleaseLink> バージョンをキーにした、古い順のリンク
     */
    private function releaseLinks(array $releases, array $changes): array
    {
        $counts = [];

        foreach ($changes as $change) {
            $version = (string) $change->version;
            $counts[$version][$change->kind->value] = ($counts[$version][$change->kind->value] ?? 0) + 1;
        }

        $links = [];

        foreach ($releases as $release) {
            $version = (string) $release->tag->version;
            $links[$version] = new ReleaseLink(
                $version,
                SitePaths::url(SitePaths::releaseDirectory($release->tag->version)),
                $counts[$version][SymbolChangeKind::Added->value] ?? 0,
                $counts[$version][SymbolChangeKind::Removed->value] ?? 0,
                $counts[$version][SymbolChangeKind::SignatureChanged->value] ?? 0,
                $counts[$version][SymbolChangeKind::Deprecated->value] ?? 0,
            );
        }

        return $links;
    }

    /**
     * @param  list<SymbolChangeEntry>  $changes  1 つのリリースの変化
     * @param  array<string, string>  $directories
     * @param  array<string, TypePage>  $typePages
     * @return list<ChangeSection>
     */
    private function sections(array $changes, array $directories, array $typePages, bool $omitAdded): array
    {
        $sections = [];

        foreach (array_keys(self::KIND_ORDER) as $kind) {
            $items = [];
            $count = 0;

            foreach ($changes as $change) {
                if ($change->kind->value !== $kind) {
                    continue;
                }

                $count++;

                if ($omitAdded && $change->kind === SymbolChangeKind::Added) {
                    continue;
                }

                $directory = $directories[$change->fqcn] ?? null;
                $items[] = new ChangeItem(
                    $change->fqcn,
                    $change->member,
                    $directory === null ? null : self::memberUrl($directory, self::displayName($typePages[$change->fqcn], $change->member)),
                    $change->before,
                    $change->after,
                );
            }

            if ($count === 0) {
                continue;
            }

            usort($items, static fn (ChangeItem $a, ChangeItem $b) => strcmp($a->fqcn, $b->fqcn) ?: strcasecmp($a->member, $b->member));
            $sections[] = new ChangeSection(SymbolChangeKind::from($kind), $count, $items);
        }

        return $sections;
    }

    /**
     * 型のページで使っている表記（大文字小文字）に合わせる。
     */
    private static function displayName(TypePage $page, string $member): string
    {
        foreach ([...$page->members, ...$page->internalMembers] as $view) {
            if (strcasecmp($view->name, $member) === 0) {
                return $view->name;
            }
        }

        return $member;
    }

    private static function memberUrl(string $directory, string $member): string
    {
        return SitePaths::url($directory).'#'.rawurlencode($member);
    }

    /**
     * ブラウザ内の検索に使う軽量な一覧。
     *
     * `types`: [型名, 印]、`members`: [型の添字, メソッド名, いつから（なければ null）, 印]
     *
     * @param  array<string, TypePage>  $typePages
     */
    private static function searchIndex(array $typePages, ?ReleaseSummary $latest): string
    {
        $types = [];
        $members = [];

        foreach (array_values($typePages) as $index => $page) {
            $types[] = [$page->fqcn, ($page->isInternal ? self::FLAG_INTERNAL : 0)
                | ($page->isDeprecated ? self::FLAG_DEPRECATED : 0)
                | ($page->existsInLatest() ? 0 : self::FLAG_REMOVED)];

            foreach ([...$page->members, ...$page->internalMembers] as $member) {
                $members[] = [$index, $member->name, $member->since, ($member->isInternal ? self::FLAG_INTERNAL : 0)
                    | ($member->isDeprecated ? self::FLAG_DEPRECATED : 0)
                    | ($member->signature === null ? self::FLAG_REMOVED : 0)
                    | ($member->isDocblock ? self::FLAG_DOCBLOCK : 0)
                    | ($member->sinceOrEarlier ? self::FLAG_SINCE_OR_EARLIER : 0)];
            }
        }

        return json_encode([
            'latest' => $latest === null ? null : (string) $latest->tag->version,
            'types' => $types,
            'members' => $members,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
