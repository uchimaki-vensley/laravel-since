<?php

namespace App\Application\Releases;

final readonly class ReleaseSyncSettings
{
    public function __construct(
        public string $package,
        public string $minimumVersion,
    ) {}
}
