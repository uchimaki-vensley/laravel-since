<?php

namespace App\Application\Changes;

final readonly class ReleaseComparison
{
    /**
     * @param  list<ApiChange>  $changes  型の完全修飾名・メソッド名の順
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $changes,
    ) {}

    public function count(string $kind): int
    {
        return count(array_filter($this->changes, static fn (ApiChange $change) => $change->kind === $kind));
    }
}
