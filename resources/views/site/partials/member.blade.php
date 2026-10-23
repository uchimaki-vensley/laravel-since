<section class="member" id="{{ $member->name }}">
    <header class="member-header">
        <h2><a class="anchor" href="#{{ $member->name }}">{{ $member->name }}</a></h2>
        <p class="since">
            @if ($member->sincePath !== null)
                <x-site.trans :site="$site" :key="$member->sinceOrEarlier ? 'site.since.since_or_earlier' : 'site.since.since'">
                    <x-slot:version><a href="{{ $site->url($member->sincePath) }}">v{{ $member->since }}</a></x-slot:version>
                </x-site.trans>
            @else
                <span class="muted">{{ $site->t('site.since.no_record') }}</span>
            @endif
        </p>
    </header>
    <p class="badges">
        @if ($member->isStatic)
            <span class="badge">static</span>
        @endif
        @if ($member->isDocblock)
            <span class="badge badge-muted" title="{{ $site->t('site.since.docblock_method') }}">@@method</span>
        @endif
        @if ($member->isInternal)
            <span class="badge badge-muted">@@internal</span>
        @endif
        @if ($member->isDeprecated)
            <span class="badge badge-warn">@@deprecated</span>
        @endif
        @if ($member->signature === null)
            <span class="badge badge-danger">{{ $member->removedIn !== null ? $site->t('site.since.removed_in', ['version' => $member->removedIn]) : $site->t('site.since.not_in_latest') }}</span>
        @endif
    </p>
    @if ($member->signature !== null)
        <pre class="signature"><code>{{ $member->signature }}</code></pre>
    @endif
    @if ($member->docblockLines !== [])
        <pre class="docblock"><code>@foreach ($member->docblockLines as $line){{ $line }}{{ $loop->last ? '' : "\n" }}@endforeach</code></pre>
    @endif
    @if ($member->history !== [])
        <ol class="history">
            @foreach ($member->history as $entry)
                <li>
                    <a href="{{ $site->url($entry->path) }}">v{{ $entry->version }}</a>
                    <x-site.kind :site="$site" :kind="$entry->kind" />
                    <x-site.signature-change :site="$site" :before="$entry->before" :after="$entry->after" />
                </li>
            @endforeach
        </ol>
    @endif
</section>
