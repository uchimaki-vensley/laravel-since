<?php

namespace App\Console\Commands;

use App\Application\Site\SiteBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('since:build')]
#[Description('解析済みのリリースと変更履歴から静的サイト（HTML / JSON）を書き出す')]
class BuildCommand extends Command
{
    public function handle(SiteBuilder $builder): int
    {
        $result = $builder->build();

        foreach ($result->skippedTypes as $fqcn) {
            $this->components->warn("Skipped a type whose name cannot be a page path: {$fqcn}");
        }

        $this->components->info(sprintf(
            'Built the site: %d release(s), %d type(s), %d method(s).',
            $result->releases,
            $result->types,
            $result->members,
        ));

        return self::SUCCESS;
    }
}
