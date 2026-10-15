<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Releases\ApiSnapshotRepository;
use App\Application\Releases\ReleaseSummary;
use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\ParameterDeclaration;
use App\Domain\Api\TypeDeclaration;
use App\Infrastructure\Persistence\Eloquent\Models\Method;
use App\Infrastructure\Persistence\Eloquent\Models\Parameter;
use App\Infrastructure\Persistence\Eloquent\Models\Release;
use App\Infrastructure\Persistence\Eloquent\Models\Type;
use Illuminate\Support\Facades\DB;

final class EloquentApiSnapshotRepository implements ApiSnapshotRepository
{
    public function replace(ReleaseSummary $release, array $types): void
    {
        // 1 リリースで数万行になるので、モデルを経由せずにまとめて入れる。
        DB::transaction(function () use ($release, $types) {
            // methods / parameters は外部キーの cascade で消える。
            Type::query()->where('release_id', $release->id)->delete();

            foreach ($types as $type) {
                $typeId = Type::query()->insertGetId([
                    'release_id' => $release->id,
                    'fqcn' => $type->fqcn,
                    'kind' => $type->kind->value,
                    'file_path' => $type->filePath,
                    'is_internal' => $type->isInternal,
                    'is_deprecated' => $type->isDeprecated,
                ]);

                foreach ($type->methods as $method) {
                    $this->insertMethod($typeId, $method);
                }
            }

            Release::query()->whereKey($release->id)->increment('snapshot_revision', 1, ['parsed_at' => now()]);
        });
    }

    public function load(ReleaseSummary $release): ?array
    {
        // 読んでいる途中で再解析に置き換えられても混ざらないよう、1 トランザクションで読む。
        return DB::transaction(function () use ($release) {
            if (! Release::query()->whereKey($release->id)->whereNotNull('parsed_at')->exists()) {
                return null;
            }

            $parameters = [];

            foreach (Parameter::query()
                ->whereIn('method_id', Method::query()->select('methods.id')
                    ->join('types', 'types.id', '=', 'methods.type_id')
                    ->where('types.release_id', $release->id))
                ->orderBy('method_id')
                ->orderBy('position')
                ->get() as $parameter) {
                $parameters[$parameter->method_id][] = new ParameterDeclaration(
                    $parameter->position,
                    $parameter->name,
                    $parameter->type,
                    $parameter->default_value,
                    $parameter->is_variadic,
                    $parameter->by_ref,
                    $parameter->docblock_type,
                );
            }

            $methods = [];

            foreach (Method::query()
                ->whereIn('type_id', Type::query()->select('id')->where('release_id', $release->id))
                ->orderBy('id')
                ->get() as $method) {
                $methods[$method->type_id][] = new MethodDeclaration(
                    $method->name,
                    $method->visibility,
                    $method->is_static,
                    $method->return_type,
                    $method->docblock_return_type,
                    $parameters[$method->id] ?? [],
                    $method->source,
                    $method->is_internal,
                    $method->is_deprecated,
                );
            }

            return array_values(Type::query()
                ->where('release_id', $release->id)
                ->orderBy('id')
                ->get()
                ->map(static fn (Type $type) => new TypeDeclaration(
                    $type->fqcn,
                    $type->kind,
                    $type->file_path,
                    $type->is_internal,
                    $type->is_deprecated,
                    $methods[$type->id] ?? [],
                ))
                ->all());
        });
    }

    private function insertMethod(int $typeId, MethodDeclaration $method): void
    {
        $methodId = Method::query()->insertGetId([
            'type_id' => $typeId,
            'name' => $method->name,
            'visibility' => $method->visibility->value,
            'is_static' => $method->isStatic,
            'return_type' => $method->returnType,
            'docblock_return_type' => $method->docblockReturnType,
            'signature_hash' => $method->signatureHash(),
            'source' => $method->source->value,
            'is_internal' => $method->isInternal,
            'is_deprecated' => $method->isDeprecated,
        ]);

        if ($method->parameters === []) {
            return;
        }

        Parameter::query()->insert(array_map(static fn (ParameterDeclaration $parameter) => [
            'method_id' => $methodId,
            'position' => $parameter->position,
            'name' => $parameter->name,
            'type' => $parameter->type,
            'default_value' => $parameter->defaultValue,
            'is_variadic' => $parameter->isVariadic,
            'by_ref' => $parameter->byRef,
            'docblock_type' => $parameter->docblockType,
        ], $method->parameters));
    }
}
