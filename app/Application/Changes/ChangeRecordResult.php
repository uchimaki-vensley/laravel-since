<?php

namespace App\Application\Changes;

use App\Application\Releases\ReleaseSummary;

final readonly class ChangeRecordResult
{
    /**
     * @param  list<ReleaseSummary>  $recorded  今回記録したリリース
     * @param  list<ReleaseSummary>  $waiting  直前のリリースが未解析のため記録できなかったリリース
     */
    public function __construct(
        public array $recorded,
        public array $waiting,
    ) {}
}
