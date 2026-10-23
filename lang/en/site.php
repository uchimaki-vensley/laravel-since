<?php

// 静的サイトの文言（英語）。キーと `:名前` は lang/ja/site.php と揃える。
// `:名前` のうち Blade の <x-site.trans> のスロットになるもの（リンクや <code>）は、テンプレート側でマークアップを付ける。

return [
    'description' => 'Look up since which release each public Laravel API exists, and when it changed.',
    'tagline' => 'Since which Laravel version?',

    'language' => [
        'label' => 'Language',
        'name' => 'English',
    ],

    'footer' => [
        'built_from' => 'Built by parsing the source of each :package release (the code is never executed).',
        'source' => 'Source code:',
    ],

    'entry' => [
        'open' => 'View in English',
        'not_found' => 'Page not found.',
    ],

    'breadcrumb' => [
        'label' => 'Breadcrumb',
        'home' => 'Home',
        'release' => 'Release',
        'type' => 'Type',
    ],

    'kind' => [
        'added' => 'Added',
        'removed' => 'Removed',
        'signature_changed' => 'Signature changed',
        'deprecated' => 'Deprecated',
    ],

    'since' => [
        'since' => 'since :version',
        'since_or_earlier' => 'since :version or earlier',
        'no_record' => 'No record of being added',
        'removed_in' => 'Removed in v:version',
        'not_in_latest' => 'Not in the latest release',
        'docblock_method' => 'Declared with @method in the docblock of the type',
    ],

    'signature' => [
        'before' => 'Before',
        'after' => 'After',
    ],

    'index' => [
        'title' => 'Search',
        'heading' => 'Since when has this method existed?',
        'intro' => 'Look up :added and :changed for the public methods of Laravel.',
        'intro_added' => 'which release added them',
        'intro_changed' => 'when their signatures changed',
        'range' => 'Covers :package v:from to v:to.',
        'search_label' => 'Search',
        'query_label' => 'Class or method name',
        'query_placeholder' => 'e.g. Builder::incrementEach, Str slug',
        'include_internal' => 'Include :internal',
        'loading' => 'Loading the search index…',
        'noscript' => 'Search needs JavaScript. You can also browse the releases below.',
        'stats_label' => 'Coverage',
        'releases' => 'Releases',
        'types' => 'Types',
        'methods' => 'Methods',
        'releases_heading' => 'Releases',
        'no_releases' => 'No releases have been parsed yet.',
        'version' => 'Version',
        'baseline' => 'Baseline',
        'baseline_title' => 'The oldest parsed release. Every method in it counts as added.',
    ],

    // search.js が使う文言（:count・:limit・:version は search.js が置き換える）
    'search' => [
        'total' => 'Search :count types and methods.',
        'none' => 'No matches.',
        'count_one' => ':count match',
        'count_other' => ':count matches',
        'limited' => ' (showing the top :limit)',
        'error' => 'Could not load the search index.',
        'removed' => 'Removed',
    ],

    'release' => [
        'description' => 'Public APIs added, removed or changed in laravel/framework v:version.',
        'pager_label' => 'Previous and next releases',
        'no_changes' => 'No public API changes were recorded in this release.',
        'oldest' => '(This is the oldest parsed release.)',
        'omitted' => 'This is the oldest parsed release, so every method that existed at this point is recorded as added. The list is omitted.',
    ],

    'type' => [
        'description' => 'The public methods of :fqcn, and since when each one exists.',
        'not_in_latest' => 'This type is not in the latest release (v:version).',
        'toc_label' => 'Methods',
        'no_methods' => 'No public methods.',
        'internal_methods' => ':internal methods (:count)',
    ],
];
