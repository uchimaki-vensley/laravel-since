@props(['title', 'locale', 'path', 'publicUrl', 'description' => null])
@php
    $baseUrl = rtrim($publicUrl, '/');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale->value }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · laravel-since</title>
    <meta name="description" content="{{ $description ?? __('site.description') }}">
    <link rel="canonical" href="{{ $baseUrl.$path->url($locale) }}">
    @foreach (\App\Application\Site\SiteLocale::cases() as $alternative)
        <link rel="alternate" hreflang="{{ $alternative->value }}" href="{{ $baseUrl.$path->url($alternative) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $baseUrl.$path->url(\App\Application\Site\SiteLocale::English) }}">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/site.css">
    <script src="/assets/language.js" defer></script>
    {{ $head ?? '' }}
</head>
<body>
    <header class="site-header">
        <div class="container">
            <a class="brand" href="{{ \App\Application\Site\SitePath::home()->url($locale) }}">laravel<span>-since</span></a>
            <span class="tagline">{{ __('site.tagline') }}</span>
            <nav class="languages" aria-label="{{ __('site.language') }}">
                @foreach (\App\Application\Site\SiteLocale::cases() as $alternative)
                    <a data-language="{{ $path->url($alternative) }}" href="{{ $path->url($alternative) }}" lang="{{ $alternative->value }}" hreflang="{{ $alternative->value }}" @if ($alternative === $locale) aria-current="page" @endif>{{ $alternative->label() }}</a>
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
                {{ __('site.footer') }}
                {{ __('site.source') }} <a href="https://github.com/uchimaki-vensley/laravel-since">uchimaki-vensley/laravel-since</a>
            </p>
        </div>
    </footer>
</body>
</html>
