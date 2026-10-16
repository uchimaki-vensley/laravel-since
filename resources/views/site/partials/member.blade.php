<section class="member" id="{{ $member->name }}">
    <header class="member-header">
        <h2><a class="anchor" href="#{{ $member->name }}">{{ $member->name }}</a></h2>
        <p class="since">
            @if ($member->since !== null)
                <a href="{{ $member->sinceUrl }}">v{{ $member->since }}</a>{{ $member->sinceOrEarlier ? ' 以前から' : ' から' }}
            @else
                <span class="muted">追加の記録なし</span>
            @endif
        </p>
    </header>
    <p class="badges">
        @if ($member->isStatic)
            <span class="badge">static</span>
        @endif
        @if ($member->isDocblock)
            <span class="badge badge-muted" title="型の docblock の @@method で宣言されたメソッド">@@method</span>
        @endif
        @if ($member->isInternal)
            <span class="badge badge-muted">@@internal</span>
        @endif
        @if ($member->isDeprecated)
            <span class="badge badge-warn">@@deprecated</span>
        @endif
        @if ($member->signature === null)
            <span class="badge badge-danger">{{ $member->removedIn !== null ? 'v'.$member->removedIn.' で削除' : '最新のリリースにない' }}</span>
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
                    <a href="{{ $entry->url }}">v{{ $entry->version }}</a>
                    <x-site.kind :kind="$entry->kind" />
                    <x-site.signature-change :before="$entry->before" :after="$entry->after" />
                </li>
            @endforeach
        </ol>
    @endif
</section>
