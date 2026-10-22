<?php

namespace App\Application\Site\Pages;

use App\Application\Site\SitePath;
use App\Domain\Enums\TypeKind;

/**
 * 型ごとのメソッド一覧。
 */
final readonly class TypePage
{
    /**
     * @param  ?TypeKind  $kind  最新のリリースにない型では null
     * @param  list<MemberView>  $members  @internal でないもの（名前順）
     * @param  list<MemberView>  $internalMembers  @internal のもの（名前順。既定では畳んで表示する）
     */
    public function __construct(
        public string $fqcn,
        public SitePath $path,
        public ?TypeKind $kind,
        public ?string $filePath,
        public bool $isInternal,
        public bool $isDeprecated,
        public ?string $latestVersion,
        public array $members,
        public array $internalMembers,
    ) {}

    public function existsInLatest(): bool
    {
        return $this->kind !== null;
    }
}
