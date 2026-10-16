<x-site.layout title="検索">
    <x-slot:head>
        <script src="/assets/search.js" defer></script>
    </x-slot:head>

    <section class="hero">
        <h1>このメソッド、いつから？</h1>
        <p>
            Laravel の公開メソッドが <strong>どのリリースで追加され</strong>、<strong>いつシグネチャが変わったか</strong> を逆引きできます。
            @if ($page->oldestVersion !== null)
                対象は <code>laravel/framework</code> v{{ $page->oldestVersion }} 〜 v{{ $page->latestVersion }}。
            @endif
        </p>
    </section>

    <section class="search" aria-label="検索">
        <label class="search-label" for="q">クラス名・メソッド名</label>
        <input id="q" class="search-input" type="search" placeholder="例: Builder::incrementEach、Str slug" autocomplete="off" spellcheck="false" autofocus>
        <label class="search-option"><input id="include-internal" type="checkbox"> <code>@@internal</code> も含める</label>
        <p id="search-status" class="search-status" aria-live="polite">
            検索の一覧を読み込んでいます…
        </p>
        <ol id="search-results" class="search-results"></ol>
        <noscript><p class="notice">検索には JavaScript が必要です。下のリリース一覧からもたどれます。</p></noscript>
    </section>

    <section class="stats" aria-label="収録数">
        <div><span class="stat-value">{{ number_format(count($page->releases)) }}</span><span class="stat-label">リリース</span></div>
        <div><span class="stat-value">{{ number_format($page->types) }}</span><span class="stat-label">型</span></div>
        <div><span class="stat-value">{{ number_format($page->members) }}</span><span class="stat-label">メソッド</span></div>
    </section>

    <section>
        <h2>リリース</h2>
        @if ($page->releases === [])
            <p class="notice">まだ解析済みのリリースはありません。</p>
        @else
            <div class="table-scroll">
                <table class="releases">
                    <thead>
                        <tr>
                            <th scope="col">バージョン</th>
                            <th scope="col" class="num">追加</th>
                            <th scope="col" class="num">シグネチャ変更</th>
                            <th scope="col" class="num">非推奨</th>
                            <th scope="col" class="num">削除</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($page->releases as $release)
                            <tr>
                                <th scope="row">
                                    <a href="{{ $release->url }}">v{{ $release->version }}</a>
                                    @if ($loop->last)
                                        <span class="muted" title="解析した最も古いリリース。この時点のメソッドはすべて「追加」として数える">起点</span>
                                    @endif
                                </th>
                                <td class="num">{{ $release->added ? number_format($release->added) : '' }}</td>
                                <td class="num">{{ $release->signatureChanged ? number_format($release->signatureChanged) : '' }}</td>
                                <td class="num">{{ $release->deprecated ? number_format($release->deprecated) : '' }}</td>
                                <td class="num">{{ $release->removed ? number_format($release->removed) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-site.layout>
