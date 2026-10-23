@use('App\Application\Site\SitePath')
@php
    $separator = strrpos($page->fqcn, '\\');
    $shortName = $separator === false ? $page->fqcn : substr($page->fqcn, $separator + 1);
    $namespace = $separator === false ? null : substr($page->fqcn, 0, $separator);
@endphp
<x-site.layout :site="$site" :title="$shortName" :description="$site->t('site.type.description', ['fqcn' => $page->fqcn])">
    <nav class="breadcrumb" aria-label="{{ $site->t('site.breadcrumb.label') }}"><a href="{{ $site->url(SitePath::home()) }}">{{ $site->t('site.breadcrumb.home') }}</a> / {{ $site->t('site.breadcrumb.type') }}</nav>

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
            <p class="notice">{{ $site->t('site.type.not_in_latest', ['version' => (string) $page->latestVersion]) }}</p>
        @endif
    </header>

    @if ($page->members !== [])
        <nav class="toc" aria-label="{{ $site->t('site.type.toc_label') }}">
            @foreach ($page->members as $member)
                <a href="#{{ $member->name }}" @class(['removed' => $member->signature === null])>{{ $member->name }}</a>
            @endforeach
        </nav>
    @endif

    @if ($page->members === [] && $page->internalMembers === [])
        <p class="notice">{{ $site->t('site.type.no_methods') }}</p>
    @endif

    @foreach ($page->members as $member)
        @include('site.partials.member', ['member' => $member])
    @endforeach

    @if ($page->internalMembers !== [])
        <details class="internal-members">
            <summary><x-site.trans :site="$site" key="site.type.internal_methods" :replace="['count' => (string) count($page->internalMembers)]"><x-slot:internal><code>@@internal</code></x-slot:internal></x-site.trans></summary>
            @foreach ($page->internalMembers as $member)
                @include('site.partials.member', ['member' => $member])
            @endforeach
        </details>
    @endif
</x-site.layout>
