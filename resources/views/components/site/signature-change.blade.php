@props(['before', 'after'])
@if ($before !== null || $after !== null)
    <dl class="signature-change">
        @if ($before !== null)
            <dt>変更前</dt>
            <dd><code>{{ $before }}</code></dd>
        @endif
        @if ($after !== null)
            <dt>変更後</dt>
            <dd><code>{{ $after }}</code></dd>
        @endif
    </dl>
@endif
