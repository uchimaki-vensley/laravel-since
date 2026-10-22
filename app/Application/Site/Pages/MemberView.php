<?php

namespace App\Application\Site\Pages;

use App\Application\Site\SitePath;

/**
 * 型のページに載せる 1 つのメソッド。
 */
final readonly class MemberView
{
    /**
     * @param  ?string  $signature  最新のリリースでのシグネチャ（削除済みなら null）
     * @param  list<string>  $docblockLines  ネイティブの型と別に docblock に書かれた型（`@param` / `@return`）
     * @param  ?string  $since  いつから存在するか（最後に追加されたリリース。履歴がなければ null）
     * @param  bool  $sinceOrEarlier  $since が解析した最も古いリリースで、それ以前から存在した可能性がある
     * @param  ?string  $removedIn  最新のリリースにない場合、削除されたリリース
     * @param  list<HistoryEntry>  $history  古いリリースから順
     */
    public function __construct(
        public string $name,
        public ?string $signature,
        public array $docblockLines,
        public ?string $since,
        public ?SitePath $sincePath,
        public bool $sinceOrEarlier,
        public bool $isStatic,
        public bool $isDocblock,
        public bool $isInternal,
        public bool $isDeprecated,
        public ?string $removedIn,
        public array $history,
    ) {}
}
