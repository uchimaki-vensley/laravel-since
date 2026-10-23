@use('App\Application\Site\SitePath')
<x-site.layout :site="$site" :title="'v'.$page->release->version" :description="$site->t('site.release.description', ['version' => $page->release->version])">
    <nav class="breadcrumb" aria-label="{{ $site->t('site.breadcrumb.label') }}"><a href="{{ $site->url(SitePath::home()) }}">{{ $site->t('site.breadcrumb.home') }}</a> / {{ $site->t('site.breadcrumb.release') }}</nav>

    <h1>v{{ $page->release->version }}</h1>

    <nav class="pager" aria-label="{{ $site->t('site.release.pager_label') }}">
        @if ($page->previous !== null)
            <a rel="prev" href="{{ $site->url($page->previous->path) }}">← v{{ $page->previous->version }}</a>
        @endif
        @if ($page->next !== null)
            <a rel="next" href="{{ $site->url($page->next->path) }}">v{{ $page->next->version }} →</a>
        @endif
    </nav>

    @if ($page->sections === [])
        <p class="notice">
            {{ $site->t('site.release.no_changes') }}
            @if ($page->previous === null)
                {{ $site->t('site.release.oldest') }}
            @endif
        </p>
    @endif

    @foreach ($page->sections as $section)
        <section class="change-section">
            <h2><x-site.kind :site="$site" :kind="$section->kind" /> <span class="count">{{ number_format($section->count) }}</span></h2>
            @if ($section->isOmitted())
                <p class="notice">{{ $site->t('site.release.omitted') }}</p>
            @endif
            @foreach (collect($section->items)->groupBy('fqcn') as $fqcn => $items)
                <div class="change-group">
                    <h3><code>{{ $fqcn }}</code></h3>
                    <ul class="change-list">
                        @foreach ($items as $item)
                            <li>
                                @if ($item->path !== null)
                                    <a href="{{ $site->url($item->path) }}"><code>{{ $item->member }}</code></a>
                                @else
                                    <code>{{ $item->member }}</code>
                                @endif
                                <x-site.signature-change :site="$site" :before="$item->before" :after="$item->after" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>
    @endforeach
</x-site.layout>
