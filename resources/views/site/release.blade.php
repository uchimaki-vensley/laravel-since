<x-site.layout :public-url="$publicUrl" :locale="$locale" :directory="$directory" :title="'v'.$page->release->version" :description="__('site.release_description', ['version' => $page->release->version])">
    <nav class="breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ $siteUrl('/') }}">{{ __('site.home') }}</a> / {{ __('site.releases') }}</nav>

    <h1>v{{ $page->release->version }}</h1>

    <nav class="pager" aria-label="{{ __('site.adjacent') }}">
        @if ($page->previous !== null)
            <a rel="prev" href="{{ $siteUrl($page->previous->url) }}">← v{{ $page->previous->version }}</a>
        @endif
        @if ($page->next !== null)
            <a rel="next" href="{{ $siteUrl($page->next->url) }}">v{{ $page->next->version }} →</a>
        @endif
    </nav>

    @if ($page->sections === [])
        <p class="notice">
            {{ __('site.no_changes') }}
            @if ($page->previous === null)
                {{ __('site.oldest_note') }}
            @endif
        </p>
    @endif

    @foreach ($page->sections as $section)
        <section class="change-section">
            <h2><x-site.kind :kind="$section->kind" /> <span class="count">{{ $siteNumber($section->count) }}</span></h2>
            @if ($section->isOmitted())
                <p class="notice">{{ __('site.omitted') }}</p>
            @endif
            @foreach (collect($section->items)->groupBy('fqcn') as $fqcn => $items)
                <div class="change-group">
                    <h3><code>{{ $fqcn }}</code></h3>
                    <ul class="change-list">
                        @foreach ($items as $item)
                            <li>
                                @if ($item->url !== null)
                                    <a href="{{ $siteUrl($item->url) }}"><code>{{ $item->member }}</code></a>
                                @else
                                    <code>{{ $item->member }}</code>
                                @endif
                                <x-site.signature-change :before="$item->before" :after="$item->after" />
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>
    @endforeach
</x-site.layout>
