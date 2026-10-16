@php
    $separator = strrpos($page->fqcn, '\\');
    $shortName = $separator === false ? $page->fqcn : substr($page->fqcn, $separator + 1);
    $namespace = $separator === false ? null : substr($page->fqcn, 0, $separator);
@endphp
<x-site.layout :title="$shortName" :description="$page->fqcn.' の公開メソッドと、それぞれがいつから存在するか。'">
    <nav class="breadcrumb" aria-label="パンくず"><a href="/">トップ</a> / 型</nav>

    <header class="type-header">
        <h1>{{ $shortName }}</h1>
        <p class="fqcn">
            @if ($namespace !== null)<span class="namespace">{{ $namespace }}\</span>@endif{{ $shortName }}
        </p>
        <p class="badges">
            @if ($page->kind !== null)
                <span class="badge">{{ $page->kind->value }}</span>
            @endif
            @if ($page->isInternal)
                <span class="badge badge-muted">@@internal</span>
            @endif
            @if ($page->isDeprecated)
                <span class="badge badge-warn">@@deprecated</span>
            @endif
            @if ($page->filePath !== null)
                <span class="file-path">{{ $page->filePath }}</span>
            @endif
        </p>
        @if (! $page->existsInLatest())
            <p class="notice">この型は最新のリリース（v{{ $page->latestVersion }}）にはありません。</p>
        @endif
    </header>

    @if ($page->members !== [])
        <nav class="toc" aria-label="メソッドの一覧">
            @foreach ($page->members as $member)
                <a href="#{{ $member->name }}" @class(['removed' => $member->signature === null])>{{ $member->name }}</a>
            @endforeach
        </nav>
    @endif

    @if ($page->members === [] && $page->internalMembers === [])
        <p class="notice">公開メソッドはありません。</p>
    @endif

    @foreach ($page->members as $member)
        @include('site.partials.member', ['member' => $member])
    @endforeach

    @if ($page->internalMembers !== [])
        <details class="internal-members">
            <summary><code>@@internal</code> のメソッド（{{ count($page->internalMembers) }}）</summary>
            @foreach ($page->internalMembers as $member)
                @include('site.partials.member', ['member' => $member])
            @endforeach
        </details>
    @endif
</x-site.layout>
