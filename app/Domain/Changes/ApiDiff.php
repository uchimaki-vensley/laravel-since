<?php

namespace App\Domain\Changes;

use App\Domain\Api\MethodDeclaration;
use App\Domain\Api\TypeDeclaration;
use App\Domain\Enums\SymbolChangeKind;

/**
 * 2 つのスナップショット（リリースの公開 API）を比べる。
 */
final class ApiDiff
{
    /**
     * $before から $after への変化を、シンボルのキー順に返す。
     *
     * - 追加: $after にだけあるメソッド
     * - 削除: $before にだけあるメソッド（型ごと消えた場合も含む）
     * - シグネチャ変更: シグネチャのハッシュが違うメソッド
     * - 非推奨化: $before では非推奨でなく、$after で非推奨になったメソッド（シグネチャ変更と同時にも起こる）
     *
     * @param  list<TypeDeclaration>  $before
     * @param  list<TypeDeclaration>  $after
     * @return list<MethodChange>
     */
    public static function between(array $before, array $after): array
    {
        $old = self::index($before);
        $new = self::index($after);
        $changes = [];

        foreach ($new as $key => [$symbol, $method]) {
            $previous = $old[$key][1] ?? null;

            if ($previous === null) {
                $changes[$key] = [new MethodChange($symbol, SymbolChangeKind::Added, null, $method)];

                continue;
            }

            if ($previous->signatureHash() !== $method->signatureHash()) {
                $changes[$key][] = new MethodChange($symbol, SymbolChangeKind::SignatureChanged, $previous, $method);
            }

            if (! $previous->isDeprecated && $method->isDeprecated) {
                $changes[$key][] = new MethodChange($symbol, SymbolChangeKind::Deprecated, $previous, $method);
            }
        }

        foreach ($old as $key => [$symbol, $method]) {
            if (! isset($new[$key])) {
                $changes[$key] = [new MethodChange($symbol, SymbolChangeKind::Removed, $method, null)];
            }
        }

        ksort($changes, SORT_STRING);

        return array_merge(...array_values($changes));
    }

    /**
     * スナップショットに含まれるメソッドの名前。
     *
     * @param  list<TypeDeclaration>  $types
     * @return list<SymbolName>
     */
    public static function symbols(array $types): array
    {
        return array_values(array_map(static fn (array $entry) => $entry[0], self::index($types)));
    }

    /**
     * 大文字小文字だけが違う名前は同じメソッドとして扱う（最初のものを使う）。
     *
     * @param  list<TypeDeclaration>  $types
     * @return array<string, array{SymbolName, MethodDeclaration}>
     */
    private static function index(array $types): array
    {
        $index = [];

        foreach ($types as $type) {
            foreach ($type->methods as $method) {
                $symbol = new SymbolName($type->fqcn, $method->name);
                $index[$symbol->key()] ??= [$symbol, $method];
            }
        }

        return $index;
    }
}
