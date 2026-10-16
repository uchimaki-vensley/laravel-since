<?php

namespace App\Application\Site;

/** Supported languages also form the allowlist for generated path prefixes. */
enum SiteLocale: string
{
    case English = 'en';
    case Japanese = 'ja';
    case Hungarian = 'hu';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Japanese => '日本語',
            self::Hungarian => 'Magyar',
        };
    }

    public function url(string $path = '/'): string
    {
        return '/'.$this->value.'/'.ltrim($path, '/');
    }
}
