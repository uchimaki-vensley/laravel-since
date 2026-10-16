@props(['kind'])
@php
    $label = match ($kind) {
        \App\Domain\Enums\SymbolChangeKind::Added => '追加',
        \App\Domain\Enums\SymbolChangeKind::Removed => '削除',
        \App\Domain\Enums\SymbolChangeKind::SignatureChanged => 'シグネチャ変更',
        \App\Domain\Enums\SymbolChangeKind::Deprecated => '非推奨',
    };
@endphp
<span class="kind kind-{{ $kind->value }}">{{ $label }}</span>
