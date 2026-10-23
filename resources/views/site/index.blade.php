<x-site.layout :site="$site" :title="$site->t('site.index.title')">
    <x-slot:head>
        <script src="/assets/search.js" defer></script>
    </x-slot:head>

    <section class="hero">
        <h1>{{ $site->t('site.index.heading') }}</h1>
        <p>
            <x-site.trans :site="$site" key="site.index.intro">
                <x-slot:added><strong>{{ $site->t('site.index.intro_added') }}</strong></x-slot:added>
                <x-slot:changed><strong>{{ $site->t('site.index.intro_changed') }}</strong></x-slot:changed>
            </x-site.trans>
            @if ($page->oldestVersion !== null && $page->latestVersion !== null)
                <x-site.trans :site="$site" key="site.index.range" :replace="['from' => $page->oldestVersion, 'to' => $page->latestVersion]">
                    <x-slot:package><code>laravel/framework</code></x-slot:package>
                </x-site.trans>
            @endif
        </p>
    </section>

    {{-- search.js が使う文言は data-text-* で渡す（:count などは search.js が置き換える）。 --}}
    <section class="search" aria-label="{{ $site->t('site.index.search_label') }}"
        data-text-total="{{ $site->t('site.search.total') }}"
        data-text-none="{{ $site->t('site.search.none') }}"
        data-text-count-one="{{ $site->t('site.search.count_one') }}"
        data-text-count-other="{{ $site->t('site.search.count_other') }}"
        data-text-limited="{{ $site->t('site.search.limited') }}"
        data-text-error="{{ $site->t('site.search.error') }}"
        data-text-removed="{{ $site->t('site.search.removed') }}"
        data-text-since="{{ $site->t('site.since.since') }}"
        data-text-since-or-earlier="{{ $site->t('site.since.since_or_earlier') }}">
        <label class="search-label" for="q">{{ $site->t('site.index.query_label') }}</label>
        <input id="q" class="search-input" type="search" placeholder="{{ $site->t('site.index.query_placeholder') }}" autocomplete="off" spellcheck="false" autofocus>
        <label class="search-option"><input id="include-internal" type="checkbox">
            <x-site.trans :site="$site" key="site.index.include_internal"><x-slot:internal><code>@@internal</code></x-slot:internal></x-site.trans>
        </label>
        <p id="search-status" class="search-status" aria-live="polite">
            {{ $site->t('site.index.loading') }}
        </p>
        <ol id="search-results" class="search-results"></ol>
        <noscript><p class="notice">{{ $site->t('site.index.noscript') }}</p></noscript>
    </section>

    <section class="stats" aria-label="{{ $site->t('site.index.stats_label') }}">
        <div><span class="stat-value">{{ number_format(count($page->releases)) }}</span><span class="stat-label">{{ $site->t('site.index.releases') }}</span></div>
        <div><span class="stat-value">{{ number_format($page->types) }}</span><span class="stat-label">{{ $site->t('site.index.types') }}</span></div>
        <div><span class="stat-value">{{ number_format($page->members) }}</span><span class="stat-label">{{ $site->t('site.index.methods') }}</span></div>
    </section>

    <section>
        <h2>{{ $site->t('site.index.releases_heading') }}</h2>
        @if ($page->releases === [])
            <p class="notice">{{ $site->t('site.index.no_releases') }}</p>
        @else
            <div class="table-scroll">
                <table class="releases">
                    <thead>
                        <tr>
                            <th scope="col">{{ $site->t('site.index.version') }}</th>
                            <th scope="col" class="num">{{ $site->t('site.kind.added') }}</th>
                            <th scope="col" class="num">{{ $site->t('site.kind.signature_changed') }}</th>
                            <th scope="col" class="num">{{ $site->t('site.kind.deprecated') }}</th>
                            <th scope="col" class="num">{{ $site->t('site.kind.removed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($page->releases as $release)
                            <tr>
                                <th scope="row">
                                    <a href="{{ $site->url($release->path) }}">v{{ $release->version }}</a>
                                    @if ($loop->last)
                                        <span class="muted" title="{{ $site->t('site.index.baseline_title') }}">{{ $site->t('site.index.baseline') }}</span>
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
