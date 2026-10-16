<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Releases\ReleaseSummary;
use App\Application\Site\SiteCatalog;
use App\Application\Site\SymbolChangeEntry;
use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Releases\PackageName;
use App\Domain\Releases\Version;
use App\Infrastructure\Persistence\Eloquent\Models\Method;
use App\Infrastructure\Persistence\Eloquent\Models\Parameter;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\SymbolChange;
use App\Infrastructure\Persistence\Eloquent\Models\Type;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use UnexpectedValueException;

final class EloquentSiteCatalog implements SiteCatalog
{
    private const int CHUNK = 200;

    public function parsedReleases(PackageName $package): array
    {
        $releases = Release::query()
            ->where('package', $package->value)
            ->whereNotNull('parsed_at')
            ->whereNotNull('changes_basis')
            ->get()
            ->map(ReleaseSummaryMapper::toSummary(...))
            ->all();

        usort($releases, static fn (ReleaseSummary $a, ReleaseSummary $b) => $a->tag->version->compare($b->tag->version));

        return $releases;
    }

    public function types(ReleaseSummary $release): array
    {
        $types = [];

        // 1 リリースでメソッドが 1 万件を超えるので、モデルをまとめて持たないよう少しずつ読む。
        Type::query()
            ->where('release_id', $release->id)
            ->with(['methods' => static fn (HasMany $query) => $query->orderBy('id')->with([
                'parameters' => static fn (HasMany $query) => $query->orderBy('position'),
            ])])
            ->chunkById(self::CHUNK, static function (Collection $chunk) use (&$types): void {
                foreach ($chunk as $type) {
                    $types[] = self::toType($type);
                }
            });

        usort($types, static fn (TypeDeclaration $a, TypeDeclaration $b) => strcmp($a->fqcn, $b->fqcn));

        return $types;
    }

    public function changes(PackageName $package): array
    {
        $changes = [];
        $versions = [];

        SymbolChange::query()
            ->whereHas('release', static fn (Builder $query) => $query->where('package', $package->value))
            ->with(['symbol', 'release'])
            ->chunkById(self::CHUNK * 10, static function (Collection $chunk) use (&$changes, &$versions): void {
                foreach ($chunk as $change) {
                    // 外部キーがあるので、関連は必ず存在する。
                    $symbol = $change->symbol ?? throw new UnexpectedValueException("Symbol change {$change->id} has no symbol.");
                    $release = $change->release ?? throw new UnexpectedValueException("Symbol change {$change->id} has no release.");

                    $changes[] = new SymbolChangeEntry(
                        $symbol->fqcn,
                        $symbol->member,
                        $versions[$release->version] ??= Version::parse($release->version),
                        $change->kind,
                        self::signature($change->before),
                        self::signature($change->after),
                    );
                }
            });

        return $changes;
    }

    private static function toType(Type $type): TypeDeclaration
    {
        return new TypeDeclaration(
            $type->fqcn,
            $type->kind,
            $type->file_path,
            $type->is_internal,
            $type->is_deprecated,
            array_values($type->methods->map(static fn (Method $method) => new MethodDeclaration(
                $method->name,
                $method->visibility,
                $method->is_static,
                $method->return_type,
                $method->docblock_return_type,
                array_values($method->parameters->map(static fn (Parameter $parameter) => new ParameterDeclaration(
                    $parameter->position,
                    $parameter->name,
                    $parameter->type,
                    $parameter->default_value,
                    $parameter->is_variadic,
                    $parameter->by_ref,
                    $parameter->docblock_type,
                ))->all()),
                $method->source,
                $method->is_internal,
                $method->is_deprecated,
            ))->all()),
        );
    }

    /**
     * 変化前後の JSON から表示用のシグネチャを取り出す（`{"signature": "..."}` の形）。
     */
    private static function signature(mixed $value): ?string
    {
        return is_array($value) && isset($value['signature']) && is_string($value['signature']) ? $value['signature'] : null;
    }
}
