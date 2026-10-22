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

    /**
     * 3 桁ごとの区切り文字（CLDR の数値記号）。ハンガリー語は改行しない空白。
     */
    public function groupSeparator(): string
    {
        return match ($this) {
            self::Hungarian => "\u{00a0}",
            default => ',',
        };
    }

    /**
     * 区切りを入れ始める最小の桁数から 3 を引いた数（CLDR の minimumGroupingDigits）。
     * ハンガリー語は 4 桁の数を区切らない（1646、12 345）。
     */
    public function minimumGroupingDigits(): int
    {
        return match ($this) {
            self::Hungarian => 2,
            default => 1,
        };
    }

    /**
     * 件数を表示する。検索 JS（assets/search.js）も data-* 属性で同じ規則を受け取って使う。
     */
    public function formatNumber(int $number): string
    {
        if (strlen((string) abs($number)) < 3 + $this->minimumGroupingDigits()) {
            return (string) $number;
        }

        return number_format($number, 0, '.', $this->groupSeparator());
    }
}
