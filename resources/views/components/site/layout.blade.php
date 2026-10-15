@props(['title', 'description' => 'Laravel の公開 API が、どのリリースから存在し、いつ変わったかを引けるサイト。'])
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · laravel-since</title>
    <meta name="description" content="{{ $description }}">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/site.css">
    {{ $head ?? '' }}
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a class="brand" href="/">laravel<span>-since</span></a>
            <span class="tagline">Laravel の API はいつから？</span>
        </div>
    </header>
    <main class="container">
        {{ $slot }}
    </main>
    <footer class="site-footer">
        <div class="container">
            <p>
                <code>laravel/framework</code> の各リリースのソースを構文解析して作っています（コードは実行していません）。
                ソースコード: <a href="https://github.com/uchimaki-vensley/laravel-since">uchimaki-vensley/laravel-since</a>
            </p>
        </div>
    </footer>
</body>
</html>
