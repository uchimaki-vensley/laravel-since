<?php

return [
    'package' => env('SINCE_PACKAGE', 'laravel/framework'),
    'minimum_version' => env('SINCE_MINIMUM_VERSION', '11.0.0'),
    'github_token' => env('GITHUB_TOKEN'),
    'github_api_url' => 'https://api.github.com',
    'max_download_bytes' => 104857600,
    'max_archive_file_bytes' => 20971520,
    'max_archive_bytes' => 524288000,
    'max_archive_entries' => 100000,
    'http_timeout_seconds' => 30,
];
