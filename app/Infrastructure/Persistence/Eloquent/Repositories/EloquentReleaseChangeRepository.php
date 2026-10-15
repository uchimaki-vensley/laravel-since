<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Changes\MethodSignatureFormatter;
use App\Application\Changes\ReleaseChangeRepository;
use App\Application\Changes\ReleaseChangeState;
use App\Application\Releases\ReleaseSummary;
use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Changes\MethodChange;
use App\Domain\Changes\SymbolName;
use App\Domain\Releases\PackageName;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Symbol;
use App\Infrastructure\Persistence\Eloquent\Models\SymbolChange;
use Illuminate\Support\Facades\DB;

final class EloquentReleaseChangeRepository implements ReleaseChangeRepository
{
    public function states(PackageName $package): array
    {
        $states = Release::query()
            ->where('package', $package->value)
            ->get()
            ->map(static fn (Release $release) => new ReleaseChangeState(
                ReleaseSummaryMapper::toSummary($release),
                $release->parsed_at === null ? null : $release->snapshot_revision,
                $release->changes_basis,
            ))
            ->all();

        usort($states, static fn (ReleaseChangeState $a, ReleaseChangeState $b) => $a->release->tag->version->compare($b->release->tag->version));

        return $states;
    }

    public function replace(ReleaseSummary $release, string $basis, array $changes): void
    {
        DB::transaction(function () use ($release, $basis, $changes) {
            $ids = $this->symbolIds(array_map(static fn (MethodChange $change) => $change->symbol, $changes));

            SymbolChange::query()->where('release_id', $release->id)->delete();

            $rows = array_map(static fn (MethodChange $change) => [
                'symbol_id' => $ids[$change->symbol->key()],
                'release_id' => $release->id,
                'kind' => $change->kind->value,
                'before' => self::json($change->before),
                'after' => self::json($change->after),
            ], $changes);

            // SQLite のバインド変数の上限を超えないよう分けて入れる。
            foreach (array_chunk($rows, 500) as $chunk) {
                SymbolChange::query()->insert($chunk);
            }

            Release::query()->whereKey($release->id)->update(['changes_basis' => $basis]);
        });
    }

    /**
     * シンボルを（なければ）作り、キーから ID を引ける表を返す。
     * symbols の一意キーは大文字小文字を区別するので、同一性は SymbolName::key() で判定する。
     *
     * @param  list<SymbolName>  $names
     * @return array<string, int>
     */
    private function symbolIds(array $names): array
    {
        $ids = [];
        $lastId = 0;

        foreach (Symbol::query()->get(['id', 'fqcn', 'member']) as $symbol) {
            $ids[(new SymbolName($symbol->fqcn, $symbol->member))->key()] ??= $symbol->id;
            $lastId = max($lastId, $symbol->id);
        }

        $missing = [];

        foreach ($names as $name) {
            if (! isset($ids[$name->key()])) {
                $missing[$name->key()] = ['fqcn' => $name->fqcn, 'member' => $name->member];
            }
        }

        if ($missing === []) {
            return $ids;
        }

        foreach (array_chunk(array_values($missing), 500) as $chunk) {
            Symbol::query()->insert($chunk);
        }

        foreach (Symbol::query()->where('id', '>', $lastId)->get(['id', 'fqcn', 'member']) as $symbol) {
            $ids[(new SymbolName($symbol->fqcn, $symbol->member))->key()] ??= $symbol->id;
        }

        return $ids;
    }

    /**
     * 変更履歴に残すシグネチャ。表示用の文字列（signature）と、比較に使った値をそのまま持つ。
     */
    private static function json(?MethodDeclaration $method): ?string
    {
        if ($method === null) {
            return null;
        }

        return json_encode([
            'signature' => MethodSignatureFormatter::format($method),
            'name' => $method->name,
            'source' => $method->source->value,
            'is_static' => $method->isStatic,
            'return_type' => $method->returnType,
            'docblock_return_type' => $method->docblockReturnType,
            'parameters' => array_map(static fn (ParameterDeclaration $parameter) => [
                'name' => $parameter->name,
                'type' => $parameter->type,
                'default_value' => $parameter->defaultValue,
                'is_variadic' => $parameter->isVariadic,
                'by_ref' => $parameter->byRef,
                'docblock_type' => $parameter->docblockType,
            ], $method->parameters),
            'is_internal' => $method->isInternal,
            'is_deprecated' => $method->isDeprecated,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
