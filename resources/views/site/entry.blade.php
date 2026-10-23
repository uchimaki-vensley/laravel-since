@use('App\Application\Site\SiteLocale')
@use('App\Application\Site\SitePath')
{{-- 言語の付かないトップ（index.html）と 404（404.html）。language.js が、ブラウザの言語に合うページへ移る。 --}}
<!DOCTYPE html>
<html lang="{{ $site->locale->value }}" data-locales="{{ $site->localeCodes() }}" data-default-locale="{{ SiteLocale::default()->value }}" data-redirect>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>laravel-since</title>
    @if ($notFound)
        <meta name="robots" content="noindex">
    @else
        <meta name="description" content="{{ $site->t('site.description') }}">
        @foreach (SiteLocale::cases() as $locale)
            <link rel="alternate" hreflang="{{ $locale->value }}" href="{{ $site->absoluteUrl(SitePath::home(), $locale) }}">
        @endforeach
        <link rel="alternate" hreflang="x-default" href="{{ $site->entryUrl() }}">
    @endif
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/site.css">
    <script src="/assets/language.js"></script>
</head>
<body>
    <header class="site-header">
        <div class="container">
            <span class="brand">laravel<span>-since</span></span>
        </div>
    </header>
    <main class="container">
        @foreach (SiteLocale::cases() as $locale)
            <section class="entry" lang="{{ $locale->value }}">
                <p class="entry-lead">{{ $notFound ? $site->t('site.entry.not_found', [], $locale) : $site->t('site.tagline', [], $locale) }}</p>
                <p><a href="{{ $site->url(SitePath::home(), $locale) }}" hreflang="{{ $locale->value }}" data-locale="{{ $locale->value }}">{{ $site->t('site.entry.open', [], $locale) }}</a></p>
            </section>
        @endforeach
    </main>
</body>
</html>
