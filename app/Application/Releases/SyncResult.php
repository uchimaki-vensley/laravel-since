<?php

namespace App\Application\Releases;

final readonly class SyncResult
{
    /**
     * @param  list<ReleaseSummary>  $unparsed
     */
    public function __construct(
        public int $registered,
        public array $unparsed,
    ) {}
}
