@props(['site', 'title', 'description' => null])
@use('App\Application\Site\SiteLocale')
@use('App\Application\Site\SitePath')
<!DOCTYPE html>
<html lang="{{ $site->locale->value }}" data-locales="{{ $site->localeCodes() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · laravel-since</title>
    <meta name="description" content="{{ $description ?? $site->t('site.description') }}">
    @foreach (SiteLocale::cases() as $locale)
        <link rel="alternate" hreflang="{{ $locale->value }}" href="{{ $site->absoluteUrl($site->path, $locale) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $site->absoluteUrl($site->path, SiteLocale::default()) }}">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/site.css">
    <script src="/assets/language.js" defer></script>
    {{ $head ?? '' }}
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a class="brand" href="{{ $site->url(SitePath::home()) }}">laravel<span>-since</span></a>
            <span class="tagline">{{ $site->t('site.tagline') }}</span>
            <nav class="language" aria-label="{{ $site->t('site.language.label') }}">
                @foreach ($site->otherLocales() as $locale)
                    <a href="{{ $site->url($site->path, $locale) }}" hreflang="{{ $locale->value }}" lang="{{ $locale->value }}" data-locale="{{ $locale->value }}">{{ $site->t('site.language.name', [], $locale) }}</a>
                @endforeach
            </nav>
        </div>
    </header>
    <main class="container">
        {{ $slot }}
    </main>
    <footer class="site-footer">
        <div class="container">
            <p>
                <x-site.trans :site="$site" key="site.footer.built_from"><x-slot:package><code>laravel/framework</code></x-slot:package></x-site.trans>
                {{ $site->t('site.footer.source') }} <a href="https://github.com/uchimaki-vensley/laravel-since">uchimaki-vensley/laravel-since</a>
            </p>
        </div>
    </footer>
</body>
</html>
