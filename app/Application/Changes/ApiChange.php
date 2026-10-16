<?php

namespace App\Application\Changes;

/**
 * 表示用の 1 件の変化。シグネチャは MethodSignatureFormatter の形式。
 */
final readonly class ApiChange
{
    /**
     * @param  'added'|'removed'|'signature_changed'|'deprecated'  $kind
     */
    public function __construct(
        public string $fqcn,
        public string $member,
        public string $kind,
        public ?string $before,
        public ?string $after,
    ) {}
}
