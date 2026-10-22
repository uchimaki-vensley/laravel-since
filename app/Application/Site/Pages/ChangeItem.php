<?php

namespace App\Application\Site\Pages;

use App\Application\Site\SitePath;

final readonly class ChangeItem
{
    /**
     * @param  ?SitePath  $path  メンバーの履歴へのリンク（型のページを出力しない場合は null）
     */
    public function __construct(
        public string $fqcn,
        public string $member,
        public ?SitePath $path,
        public ?string $before,
        public ?string $after,
    ) {}
}
