<x-site.layout :title="'v'.$page->release->version" :description="'laravel/framework v'.$page->release->version.' で追加・削除・変更された公開 API。'">
    <nav class="breadcrumb" aria-label="パンくず"><a href="/">トップ</a> / リリース</nav>

    <h1>v{{ $page->release->version }}</h1>

    <nav class="pager" aria-label="前後のリリース">
        @if ($page->previous !== null)
            <a rel="prev" href="{{ $page->previous->url }}">← v{{ $page->previous->version }}</a>
        @endif
        @if ($page->next !== null)
            <a rel="next" href="{{ $page->next->url }}">v{{ $page->next->version }} →</a>
        @endif
    </nav>

    @if ($page->sections === [])
        <p class="notice">
            このリリースで記録された公開 API の変化はありません。
            @if ($page->previous === null)
                （解析した最も古いリリースです）
            @endif
        </p>
    @endif

    @foreach ($page->sections as $section)
        <section class="change-section">
            <h2><x-site.kind :kind="$section->kind" /> <span class="count">{{ number_format($section->count) }}</span></h2>
            @if ($section->isOmitted())
                <p class="notice">解析した最も古いリリースなので、この時点で存在したメソッドはすべて「追加」として記録しています。一覧は省略します。</p>
            @endif
            @foreach (collect($section->items)->groupBy('fqcn') as $fqcn => $items)
                <div class="change-group">
                    <h3><code>{{ $fqcn }}</code></h3>
                    <ul class="change-list">
                        @foreach ($items as $item)
                            <li>
                                @if ($item->url !== null)
                                    <a href="{{ $item->url }}"><code>{{ $item->member }}</code></a>
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
