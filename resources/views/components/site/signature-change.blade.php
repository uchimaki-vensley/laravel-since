@props(['before', 'after'])
@if ($before !== null || $after !== null)
    <dl class="signature-change">
        @if ($before !== null)
            <dt>{{ __('site.before') }}</dt>
            <dd><code>{{ $before }}</code></dd>
        @endif
        @if ($after !== null)
            <dt>{{ __('site.after') }}</dt>
            <dd><code>{{ $after }}</code></dd>
        @endif
    </dl>
@endif
