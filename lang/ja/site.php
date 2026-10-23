<?php

// 静的サイトの文言（日本語）。キーと `:名前` は lang/en/site.php と揃える。
// `:名前` のうち Blade の <x-site.trans> のスロットになるもの（リンクや <code>）は、テンプレート側でマークアップを付ける。

return [
    'description' => 'Laravel の公開 API が、どのリリースから存在し、いつ変わったかを引けるサイト。',
    'tagline' => 'Laravel の API はいつから？',

    'language' => [
        'label' => '言語',
        'name' => '日本語',
    ],

    'footer' => [
        'built_from' => ':package の各リリースのソースを構文解析して作っています（コードは実行していません）。',
        'source' => 'ソースコード:',
    ],

    'entry' => [
        'open' => '日本語で見る',
        'not_found' => 'ページが見つかりません。',
    ],

    'breadcrumb' => [
        'label' => 'パンくず',
        'home' => 'トップ',
        'release' => 'リリース',
        'type' => '型',
    ],

    'kind' => [
        'added' => '追加',
        'removed' => '削除',
        'signature_changed' => 'シグネチャ変更',
        'deprecated' => '非推奨',
    ],

    'since' => [
        'since' => ':version から',
        'since_or_earlier' => ':version 以前から',
        'no_record' => '追加の記録なし',
        'removed_in' => 'v:version で削除',
        'not_in_latest' => '最新のリリースにない',
        'docblock_method' => '型の docblock の @method で宣言されたメソッド',
    ],

    'signature' => [
        'before' => '変更前',
        'after' => '変更後',
    ],

    'index' => [
        'title' => '検索',
        'heading' => 'このメソッド、いつから？',
        'intro' => 'Laravel の公開メソッドが :added、:changed を逆引きできます。',
        'intro_added' => 'どのリリースで追加され',
        'intro_changed' => 'いつシグネチャが変わったか',
        'range' => '対象は :package v:from 〜 v:to。',
        'search_label' => '検索',
        'query_label' => 'クラス名・メソッド名',
        'query_placeholder' => '例: Builder::incrementEach、Str slug',
        'include_internal' => ':internal も含める',
        'loading' => '検索の一覧を読み込んでいます…',
        'noscript' => '検索には JavaScript が必要です。下のリリース一覧からもたどれます。',
        'stats_label' => '収録数',
        'releases' => 'リリース',
        'types' => '型',
        'methods' => 'メソッド',
        'releases_heading' => 'リリース',
        'no_releases' => 'まだ解析済みのリリースはありません。',
        'version' => 'バージョン',
        'baseline' => '起点',
        'baseline_title' => '解析した最も古いリリース。この時点のメソッドはすべて「追加」として数える',
    ],

    // search.js が使う文言（:count・:limit・:version は search.js が置き換える）
    'search' => [
        'total' => ':count 件の型・メソッドから検索できます。',
        'none' => '見つかりませんでした。',
        'count_one' => ':count 件',
        'count_other' => ':count 件',
        'limited' => '（上位 :limit 件を表示）',
        'error' => '検索の一覧を読み込めませんでした。',
        'removed' => '削除済み',
    ],

    'release' => [
        'description' => 'laravel/framework v:version で追加・削除・変更された公開 API。',
        'pager_label' => '前後のリリース',
        'no_changes' => 'このリリースで記録された公開 API の変化はありません。',
        'oldest' => '（解析した最も古いリリースです）',
        'omitted' => '解析した最も古いリリースなので、この時点で存在したメソッドはすべて「追加」として記録しています。一覧は省略します。',
    ],

    'type' => [
        'description' => ':fqcn の公開メソッドと、それぞれがいつから存在するか。',
        'not_in_latest' => 'この型は最新のリリース（v:version）にはありません。',
        'toc_label' => 'メソッドの一覧',
        'no_methods' => '公開メソッドはありません。',
        'internal_methods' => ':internal のメソッド（:count）',
    ],
];
