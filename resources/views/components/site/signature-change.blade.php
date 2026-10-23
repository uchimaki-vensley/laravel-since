@props(['site', 'before', 'after'])
@if ($before !== null || $after !== null)
    <dl class="signature-change">
        @if ($before !== null)
            <dt>{{ $site->t('site.signature.before') }}</dt>
            <dd><code>{{ $before }}</code></dd>
        @endif
        @if ($after !== null)
            <dt>{{ $site->t('site.signature.after') }}</dt>
            <dd><code>{{ $after }}</code></dd>
        @endif
    </dl>
@endif
