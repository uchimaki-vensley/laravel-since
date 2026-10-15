<?php

namespace App\Application\Changes;

use App\Application\Releases\ApiSnapshotRepository;
use App\Application\Releases\ReleaseRepository;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Changes\ApiDiff;
use App\Domain\Changes\MethodChange;
use App\Domain\Releases\PackageName;
use App\Domain\Releases\Version;

final readonly class ReleaseComparerService implements ReleaseComparer
{
    public function __construct(
        private ReleaseRepository $releases,
        private ApiSnapshotRepository $snapshots,
        private PackageName $package,
    ) {}

    public function compare(string $from, string $to): ReleaseComparison
    {
        [$fromVersion, $before] = $this->snapshot($from);
        [$toVersion, $after] = $this->snapshot($to);

        return new ReleaseComparison(
            (string) $fromVersion,
            (string) $toVersion,
            array_map(static fn (MethodChange $change) => new ApiChange(
                $change->symbol->fqcn,
                $change->symbol->member,
                $change->kind->value,
                $change->before === null ? null : MethodSignatureFormatter::format($change->before),
                $change->after === null ? null : MethodSignatureFormatter::format($change->after),
            ), ApiDiff::between($before, $after)),
        );
    }

    /**
     * @return array{Version, list<TypeDeclaration>}
     */
    private function snapshot(string $value): array
    {
        $version = Version::tryParse(str_starts_with($value, 'v') ? substr($value, 1) : $value)
            ?? throw new ComparisonUnavailable("Invalid version: {$value}");
        $release = $this->releases->findByVersion($this->package, $version)
            ?? throw new ComparisonUnavailable("{$this->package->value} {$version} is not registered.");
        $types = $this->snapshots->load($release)
            ?? throw new ComparisonUnavailable("{$this->package->value} {$version} has not been parsed.");

        return [$version, $types];
    }
}
