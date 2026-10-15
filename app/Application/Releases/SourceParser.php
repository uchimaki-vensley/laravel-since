<?php

namespace App\Application\Releases;

use App\Domain\Api\TypeDeclaration;

interface SourceParser
{
    /**
     * 展開済みソースのディレクトリを構文解析し、公開 API を返す。ソースは実行しない。
     *
     * @return list<TypeDeclaration>
     */
    public function parse(string $directory): array;
}
