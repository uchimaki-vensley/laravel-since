<?php

namespace App\Application\Site\Pages;

use App\Domain\Enums\SymbolChangeKind;

final readonly class HistoryEntry
{
    public function __construct(
        public string $version,
        public string $url,
        public SymbolChangeKind $kind,
        public ?string $before,
        public ?string $after,
    ) {}
}
