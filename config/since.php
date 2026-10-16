<?php

return [

    /*
    | 解析対象のパッケージ。GitHub のリポジトリ名（owner/repo）も同じものを使う。
    */
    'package' => env('SINCE_PACKAGE', 'laravel/framework'),

    /*
    | 対象にする最も古い安定版。これより前のタグは登録しない。
    */
    'minimum_version' => env('SINCE_MINIMUM_VERSION', '11.0.0'),

    'github' => [
        // 任意。あれば GitHub API の呼び出しに使う（未認証より緩いレート制限になる）。tarball の取得には送らない。
        'token' => env('GITHUB_TOKEN'),
        'api_url' => 'https://api.github.com',
        'archive_url' => 'https://codeload.github.com',
        'connect_timeout' => 10,
        'timeout' => 60,
        'retries' => 3,
        'retry_sleep_milliseconds' => 2000,
        // ソース取得ジョブの実行回数の上限（RateLimited ミドルウェア）
        'jobs_per_minute' => 30,
    ],

    'sources' => [
        // 展開済みソースの置き場。取得したソースは構文解析の入力としてだけ使い、実行しない。
        'path' => env('SINCE_SOURCES_PATH', storage_path('app/sources')),
        'max_download_bytes' => 50 * 1024 * 1024,
        'max_extracted_bytes' => 200 * 1024 * 1024,
        'max_file_bytes' => 5 * 1024 * 1024,
        'max_entries' => 20000,
    ],

    'site' => [
        // canonical / hreflang の公開 URL。独自ドメインでは SINCE_SITE_URL を設定する。
        'url' => env('SINCE_SITE_URL', 'https://laravel-since.pages.dev'),
        // since:build の出力先。中身は毎回丸ごと置き換える。
        'path' => env('SINCE_SITE_PATH', base_path('dist')),
        // そのまま出力に含める静的ファイル（CSS・JS・_headers）
        'assets_path' => resource_path('site'),
    ],

];
