<?php

namespace App\Application\Site;

use App\Domain\Enums\SymbolChangeKind;
use App\Domain\Releases\Version;

/**
 * あるリリースで起きた、1 つのシンボルの変化。
 */
final readonly class SymbolChangeEntry
{
    /**
     * @param  ?string  $before  変化前のシグネチャ（表示用の文字列。ない場合は null）
     * @param  ?string  $after  変化後のシグネチャ（表示用の文字列。ない場合は null）
     */
    public function __construct(
        public string $fqcn,
        public string $member,
        public Version $version,
        public SymbolChangeKind $kind,
        public ?string $before,
        public ?string $after,
    ) {}
}
