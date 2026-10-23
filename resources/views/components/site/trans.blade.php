@props(['site', 'key', 'replace' => []])
@php
    // 文言の `:名前` を、同じ名前のスロット（リンクや <code> などのマークアップ）に置き換えて出力する。
    // 文言はエスケープして出し、スロットは Blade で描画済みのもの（中の値はエスケープ済み）をそのまま出す。
    $slots = get_defined_vars();
    $pieces = [];

    foreach (preg_split('/:([a-z_]+)/', $site->t($key, $replace), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $part) {
        if ($index % 2 === 0) {
            $pieces[] = $part;
        } elseif (($slots[$part] ?? null) instanceof \Illuminate\View\ComponentSlot) {
            $pieces[] = $slots[$part];
        } else {
            throw new \RuntimeException("Missing a slot for :{$part} in {$key}");
        }
    }
@endphp
@foreach ($pieces as $piece){{ $piece }}@endforeach
