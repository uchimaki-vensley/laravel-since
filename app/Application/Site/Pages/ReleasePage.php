<?php

namespace App\Application\Site\Pages;

/**
 * リリースごとの変更一覧。
 */
final readonly class ReleasePage
{
    /**
     * @param  list<ChangeSection>  $sections  変化のないリリースでは空
     */
    public function __construct(
        public ReleaseLink $release,
        public ?ReleaseLink $previous,
        public ?ReleaseLink $next,
        public array $sections,
    ) {}
}
