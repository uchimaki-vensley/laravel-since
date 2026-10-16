<?php

namespace App\Console\Commands;

use App\Application\Changes\ApiChange;
use App\Application\Changes\ComparisonUnavailable;
use App\Application\Changes\ReleaseComparer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('since:diff {from : 比較元のバージョン（例: 13.2.0）} {to : 比較先のバージョン（例: 13.3.0）}')]
#[Description('解析済みの 2 つのリリースの公開 API を比べ、追加・削除・シグネチャ変更・非推奨化を表示する')]
class DiffCommand extends Command
{
    private const array MARKERS = [
        'added' => '+',
        'removed' => '-',
        'signature_changed' => '~',
        'deprecated' => '!',
    ];

    public function handle(ReleaseComparer $comparer): int
    {
        try {
            $comparison = $comparer->compare($this->stringArgument('from'), $this->stringArgument('to'));
        } catch (ComparisonUnavailable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s → %s: %d added, %d removed, %d signature changed, %d deprecated.',
            $comparison->from,
            $comparison->to,
            $comparison->count('added'),
            $comparison->count('removed'),
            $comparison->count('signature_changed'),
            $comparison->count('deprecated'),
        ));

        $type = null;

        foreach ($comparison->changes as $change) {
            if ($change->fqcn !== $type) {
                $type = $change->fqcn;
                $this->newLine();
                $this->line($type);
            }

            $this->printChange($change);
        }

        return self::SUCCESS;
    }

    private function printChange(ApiChange $change): void
    {
        $marker = self::MARKERS[$change->kind];

        if ($change->kind === 'signature_changed') {
            $this->line("  {$marker} {$change->member}");
            $this->line("      before: {$change->before}");
            $this->line("      after:  {$change->after}");

            return;
        }

        $suffix = $change->kind === 'deprecated' ? ' (deprecated)' : '';
        $this->line("  {$marker} ".($change->after ?? $change->before).$suffix);
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
