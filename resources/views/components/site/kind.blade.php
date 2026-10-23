@props(['site', 'kind'])
<span class="kind kind-{{ $kind->value }}">{{ $site->t('site.kind.'.$kind->value) }}</span>
