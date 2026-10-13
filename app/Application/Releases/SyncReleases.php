<?php

namespace App\Application\Releases;

interface SyncReleases
{
    /** @return list<int> */
    public function execute(): array;
}
