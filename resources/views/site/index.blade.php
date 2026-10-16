<x-site.layout :public-url="$publicUrl" :locale="$locale" :directory="$directory" :title="__('site.search')">
    <x-slot:head>
        <script src="/assets/search.js" defer></script>
    </x-slot:head>

    <section class="hero">
        <h1>{{ __('site.hero') }}</h1>
        <p>
            {{ __('site.intro') }}
            @if ($page->oldestVersion !== null)
                {{ __('site.range', ['oldest' => $page->oldestVersion, 'latest' => $page->latestVersion]) }}
            @endif
        </p>
    </section>

    <section
        data-search-ready="{{ __('site.search_ready') }}"
        data-search-none="{{ __('site.search_none') }}"
        data-search-count-one="{{ __('site.search_count_one') }}"
        data-search-count="{{ __('site.search_count') }}"
        data-search-limit="{{ __('site.search_limit') }}"
        data-search-error="{{ __('site.search_error') }}"
        data-search-removed="{{ __('site.search_removed') }}"
        data-since="{{ __('site.since') }}"
        data-since-or-earlier="{{ __('site.since_or_earlier') }}"
        data-locale="{{ $locale->value }}" data-base="{{ $siteUrl('/') }}"
        class="search" aria-label="{{ __('site.search') }}">
        <label class="search-label" for="q">{{ __('site.search_label') }}</label>
        <input id="q" class="search-input" type="search" placeholder="{{ __('site.placeholder') }}" autocomplete="off" spellcheck="false" autofocus>
        <label class="search-option"><input id="include-internal" type="checkbox"> {{ __('site.include_internal') }}</label>
        <p id="search-status" class="search-status" aria-live="polite">
            {{ __('site.loading') }}
        </p>
        <ol id="search-results" class="search-results"></ol>
        <noscript><p class="notice">{{ __('site.noscript') }}</p></noscript>
    </section>

    <section class="stats" aria-label="{{ __('site.stats') }}">
        <div><span class="stat-value">{{ $siteNumber(count($page->releases)) }}</span><span class="stat-label">{{ __('site.releases') }}</span></div>
        <div><span class="stat-value">{{ $siteNumber($page->types) }}</span><span class="stat-label">{{ __('site.types') }}</span></div>
        <div><span class="stat-value">{{ $siteNumber($page->members) }}</span><span class="stat-label">{{ __('site.methods') }}</span></div>
    </section>

    <section>
        <h2>{{ __('site.releases') }}</h2>
        @if ($page->releases === [])
            <p class="notice">{{ __('site.empty') }}</p>
        @else
            <div class="table-scroll">
                <table class="releases">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('site.version') }}</th>
                            <th scope="col" class="num">{{ __('site.added') }}</th>
                            <th scope="col" class="num">{{ __('site.signature_changed') }}</th>
                            <th scope="col" class="num">{{ __('site.deprecated') }}</th>
                            <th scope="col" class="num">{{ __('site.removed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($page->releases as $release)
                            <tr>
                                <th scope="row">
                                    <a href="{{ $siteUrl($release->url) }}">v{{ $release->version }}</a>
                                    @if ($loop->last)
                                        <span class="muted" title="{{ __('site.baseline_title') }}">{{ __('site.baseline') }}</span>
                                    @endif
                                </th>
                                <td class="num">{{ $release->added ? $siteNumber($release->added) : '' }}</td>
                                <td class="num">{{ $release->signatureChanged ? $siteNumber($release->signatureChanged) : '' }}</td>
                                <td class="num">{{ $release->deprecated ? $siteNumber($release->deprecated) : '' }}</td>
                                <td class="num">{{ $release->removed ? $siteNumber($release->removed) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-site.layout>
