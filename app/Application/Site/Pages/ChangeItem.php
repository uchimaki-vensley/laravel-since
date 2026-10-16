<?php

namespace App\Application\Site\Pages;

final readonly class ChangeItem
{
    /**
     * @param  ?string  $url  メンバーの履歴へのリンク（型のページを出力しない場合は null）
     */
    public function __construct(
        public string $fqcn,
        public string $member,
        public ?string $url,
        public ?string $before,
        public ?string $after,
    ) {}
}
