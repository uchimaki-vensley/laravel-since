@php
    $separator = strrpos($page->fqcn, '\\');
    $shortName = $separator === false ? $page->fqcn : substr($page->fqcn, $separator + 1);
    $namespace = $separator === false ? null : substr($page->fqcn, 0, $separator);
@endphp
<x-site.layout :public-url="$publicUrl" :locale="$locale" :directory="$directory" :title="$shortName" :description="__('site.type_description', ['type' => $page->fqcn])">
    <nav class="breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="{{ $siteUrl('/') }}">{{ __('site.home') }}</a> / {{ __('site.types') }}</nav>

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
            <p class="notice">{{ __('site.missing_type', ['version' => $page->latestVersion]) }}</p>
        @endif
    </header>

    @if ($page->members !== [])
        <nav class="toc" aria-label="{{ __('site.method_list') }}">
            @foreach ($page->members as $member)
                <a href="#{{ $member->name }}" @class(['removed' => $member->signature === null])>{{ $member->name }}</a>
            @endforeach
        </nav>
    @endif

    @if ($page->members === [] && $page->internalMembers === [])
        <p class="notice">{{ __('site.no_methods') }}</p>
    @endif

    @foreach ($page->members as $member)
        @include('site.partials.member', ['member' => $member])
    @endforeach

    @if ($page->internalMembers !== [])
        <details class="internal-members">
            <summary>{{ __('site.internal_methods', ['count' => $siteNumber(count($page->internalMembers))]) }}</summary>
            @foreach ($page->internalMembers as $member)
                @include('site.partials.member', ['member' => $member])
            @endforeach
        </details>
    @endif
</x-site.layout>
