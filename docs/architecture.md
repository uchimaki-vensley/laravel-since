# アーキテクチャ

この文書は設計の **現時点の案** を示す。実装で変わったら、その PR で合わせて更新すること。
経緯と決定理由は [context.md](context.md)、実行環境は [infrastructure.md](infrastructure.md) を参照。

## 全体像

```
                ┌──────────────────────────── GitHub Actions（定期実行 / 手動実行）────────────────────────────┐
                │                                                                                             │
 GitHub API ──▶ │  php artisan since:sync                                                                     │
 (タグ一覧)      │    ├─ 新しいタグを検出 ─▶ Bus::batch([...])                                                 │
                │    │      FetchReleaseSource   リリースのソースを取得・展開（実行はしない）                     │
 codeload ────▶ │    │      ParseReleaseSource   nikic/php-parser で公開 API を抽出 → SQLite                    │
 (tarball)      │    │      DiffRelease          直前のリリースと比較 → 変更履歴                                │
                │    └─ 完了イベント ─▶ 通知（自分宛て。任意）                                                  │
                │                                                                                             │
                │  php artisan since:build   Blade で静的な HTML / JSON を dist/ に書き出す                     │
                │                                                                                             │
                │  wrangler pages deploy dist/   ─────────────────────────────────────────────▶ Cloudflare Pages │
                └─────────────────────────────────────────────────────────────────────────────────────────────┘
```

- Laravel は **バッチとして** 動く。Web サーバー（`php artisan serve`）は開発時のプレビューにだけ使う。
- 本番に常時動くプロセスはない。キューは 1 回の実行の中で処理しきる（`database` ドライバ + `queue:work --stop-when-empty`）。
- スケジューラは `routes/console.php` に普通に定義し、Actions からは `php artisan schedule:run` ではなく
  目的のコマンドを直接呼ぶ。VPS に移したときは cron から `schedule:run` を呼ぶだけで同じ動きになる。

## 層構成

Onion Architecture を採用し、依存は外側から内側へ向ける。
層の責務、配置、実装・レビューの詳細なルールは [guideline.md](../guideline.md) を参照。

```
Presentation → Application → Domain
Infrastructure ────────────→ Application / Domain
```

- `Domain`: Laravel や外部サービスに依存しない、公開 API の概念・値・ルール。
- `Application`: 取得・解析・差分・出力のユースケースと、永続化や外部 I/O のポート。
- `Infrastructure`: Eloquent / SQLite、HTTP、ファイル操作、parser などの技術アダプター。
- `Presentation`: `App\Http\Controllers` / `App\Console\Commands` / `App\Jobs` など Laravel の入口。Application のユースケースを呼び出す。
- Application Service はインターフェースと実装を持ち、Presentation はインターフェース経由で呼び出す。
- Repository インターフェース（ポート）は Application に置き、Application Service はそのインターフェースに依存する。Infrastructure が Repository を実装する。
- app/Providers は Service / Repository のインターフェースと実装を結び付ける Composition Root とする。
- Eloquent モデルは永続化層のレコードとして扱い、ドメインのルールを置かない。Service / Repository は具体的なユースケースを実装するときに追加し、先回りした空の interface は作らない。

Issue #3 の配置では、enum を `App\Domain\Enums`、Eloquent モデルを
`App\Infrastructure\Persistence\Eloquent\Models` に置く。

Issue #4（リリースの同期とソースの取得）の配置:

| 層 | クラス | 役割 |
| --- | --- | --- |
| Domain | `App\Domain\Releases\PackageName` / `Version` / `ReleaseTag` | パッケージ名・安定版のバージョン・タグの形式と比較 |
| Application | `App\Application\Releases\ReleaseSynchronizer` / `ReleaseSourceFetcher`（実装は `*Service`） | 同期と取得のユースケース |
| Application（ポート） | `ReleaseTagSource` / `SourceArchiveDownloader` / `ReleaseSourceStore` / `ReleaseRepository` | タグ一覧・tarball 取得・展開済みソースの置き場・永続化 |
| Infrastructure | `App\Infrastructure\GitHub\*`、`ReleaseSources\LocalReleaseSourceStore`、`Archives\SafeTarGzExtractor`、`Persistence\Eloquent\Repositories\EloquentReleaseRepository` | GitHub HTTP、ローカルへの展開、tar の検証、Eloquent |
| Presentation | `App\Console\Commands\SyncCommand`（`since:sync`）、`App\Jobs\FetchReleaseSource` | 入口とジョブ |

Issue #5（構文解析と公開 API の保存）の配置:

| 層 | クラス | 役割 |
| --- | --- | --- |
| Domain | `App\Domain\Api\TypeDeclaration` / `MethodDeclaration` / `ParameterDeclaration`、`App\Domain\Enums\MethodSource` | 公開 API の値。同名メソッドの統合規則とシグネチャのハッシュ |
| Application | `App\Application\Releases\ReleaseSourceParser`（実装は `ReleaseSourceParserService`） | 解析のユースケース |
| Application（ポート） | `SourceParser` / `ApiSnapshotRepository` | 構文解析・スナップショットの保存 |
| Infrastructure | `App\Infrastructure\PhpSource\PhpSourceParser` / `DocblockReader`、`Persistence\Eloquent\Repositories\EloquentApiSnapshotRepository` | nikic/php-parser・phpstan/phpdoc-parser、Eloquent |
| Presentation | `App\Jobs\ParseReleaseSource` | ジョブ |

設定値（`config/since.php`）は app/Providers で読み、コンストラクタ引数として Application / Infrastructure に渡す。

## データモデル（案）

```
releases            1 ──< types              1 ──< methods            1 ──< parameters
  id                      id                       id                       id
  package                 release_id               type_id                  method_id
  version (13.3.0)        fqcn                     name                     position
  tag (v13.3.0)           kind (class|interface    visibility               name
  released_at                   |trait|enum)       is_static                type (文字列表現)
  parsed_at               file_path                return_type              default_value (文字列表現)
                          is_internal              docblock_return_type     is_variadic / by_ref
                          is_deprecated            signature_hash           docblock_type
                                                   source (declared|docblock)
                                                   is_internal / is_deprecated

symbols（リリースをまたいだ同一性）           symbol_changes（差分の結果）
  id                                            id
  fqcn + member（例: Illuminate\...\Builder::incrementEach）   symbol_id
                                                release_id（変化が起きたリリース）
                                                kind (added|removed|signature_changed|deprecated)
                                                before / after（JSON: 変化前後のシグネチャ）
```

- `types` / `methods` / `parameters` はリリースごとのスナップショット。`symbols` はリリースをまたいで同じメソッドを指す ID。
- 「いつから存在するか」は `symbol_changes` の `added` を見る。シグネチャの比較は `signature_hash` で絞り込んでから詳細を比べる。
- `types.kind` / `methods.visibility` / `methods.source` / `symbol_changes.kind` は Domain の backed enum を Eloquent の enum キャストで保存する。
- 一意キー: `releases(package, version)`、`types(release_id, fqcn)`、`methods(type_id, name)`、
  `parameters(method_id, position)`、`symbols(fqcn, member)`、`symbol_changes(symbol_id, release_id, kind)`。
- データ量（2026-10 の実測）: `laravel/framework` 1 リリースあたり型 1,200〜1,700、メソッド 1 万〜1.3 万（うち `@method` 約 2,600）、
  引数約 1.7 万行。11.0 以降のリリース数 × それ。スナップショットが大きくなりすぎる場合は「変化があったときだけ行を持つ」形に切り替える。

## リリースの同期とソースの取得（`since:sync`）

1. GitHub API（`/repos/{package}/tags`）からタグを 100 件ずつ全ページ取得する。
2. `v{major}.{minor}.{patch}` 形式の安定版のうち、`since.minimum_version`（既定 `11.0.0`）以上を `releases` に登録する。
   登録済みのものは `insertOrIgnore` で無視するので、何度実行しても重複しない。
3. 未解析（`parsed_at` が null）のリリースごとに `FetchReleaseSource` → `ParseReleaseSource` のチェーンを作り、
   1 つの `Bus::batch` で投入する（`allowFailures`: 1 リリースの失敗で他を止めない）。
4. ジョブは codeload.github.com から tarball を取得し、検証しながら一時ディレクトリへ展開してから
   `{since.sources.path}/{vendor}/{name}/{version}`（既定 `storage/app/sources/laravel/framework/13.3.0`）へ rename する。
   解析（#5）は `ReleaseSourceStore::path()` でこのディレクトリを受け取る。

- 同じリリースを二重に処理しない: 展開済みディレクトリがあれば何もしない。並行実行は `WithoutOverlapping`（キーはリリース ID）で直列にする。
- レート制限: ジョブは `RateLimited('github')`（`since.github.jobs_per_minute`）。HTTP は接続失敗・429・5xx だけを再試行し、
  403（レート制限の枯渇・認証エラー）は待っても直らないので失敗させる。
- `GITHUB_TOKEN` があれば API 呼び出しに使う。なくても動く（未認証は 1 時間 60 回まで。全ページの取得に十数回使う）。
  tarball の取得にはトークンを送らない。
- 展開の検証規則は [security.md](security.md) の「取得したソースの扱い」を参照。

## 構文解析と公開 API の保存（`ParseReleaseSource`）

1. 展開済みソースの `src/Illuminate/` 配下の `.php` ファイルをパス順に読み（シンボリックリンクはたどらない）、
   `nikic/php-parser` で構文木にして `NameResolver` で名前を解決する。
2. 名前のある型（無名クラスを除く）ごとに、`public` メソッドと型の docblock の `@method` を `TypeDeclaration` にする。
3. リリースの既存のスナップショットを消してから保存し、`parsed_at` を付ける。1 トランザクションで行うので、
   途中で失敗したら前のスナップショットと `parsed_at` はそのまま残る。

- 構文エラーのファイルがあれば、そのファイルのパスを付けて失敗させる（黙って飛ばさない）。解析は決定的なので再試行しない。
- 型の文字列表現:
  - ネイティブ型: 構文木から組み立てる。クラス名は完全修飾名（先頭の `\` なし）。例: `?Illuminate\Support\Collection`、`(Countable&ArrayAccess)|null`
  - 既定値: `nikic/php-parser` の pretty printer の出力（配列は短縮構文）。例: `[]`、`self::DEFAULT`、`'widget'`
  - docblock の型と `@method` の既定値: docblock に書かれたとおり（改行と連続する空白は 1 つにまとめる）。クラス名は解決しない。
    phpdoc-parser の Printer は `(Closure(): void)|null` の括弧を落とすので使わない
- docblock は `phpstan/phpdoc-parser` で読む。`@param` / `@return` / `@method` / `@internal` / `@deprecated` を使う
  （`@phpstan-*` / `@psalm-*` は見ない）。書式が壊れていて読めない docblock は、型情報がないものとして扱う。
- `#[\Deprecated]` 属性も `is_deprecated` として記録する。
- 同じ型の中で名前（大文字小文字を区別しない）が重なるメソッドは 1 つにまとめ、`public` の宣言メソッドを `@method` より優先する
  （Issue #11 で決定。`@method` 側の情報は保存しない）。
  - 理由: PHP は外から呼べる宣言メソッドがあればそれを呼び、`__call` / `__callStatic` には来ない。`@method` は同じメソッドの説明に
    すぎず、実際のシグネチャは宣言側にある。別々に持つと同じ呼び出しが 2 行になり、差分（#6）で同じ変化を二重に数える。
  - 宣言メソッドが `protected` / `private` の場合は外から呼ぶと `__call` に転送されるので、`@method` を公開 API として記録する。
  - 実例: v13.36.0 の `Illuminate\Routing\RouteRegistrar::metadata`（`__call` で受ける属性の一覧と `@method` に載ったまま、
    同名の `public` メソッドが追加された）。v11.0.0 / v12.0.0 / v13.0.0 / v13.36.0 を調べて、該当はこの 1 件だけ。
    抜粋を `tests/Fixtures/LaravelExcerpts` に置いてテストしている。
- 同じ型が複数ある場合（`if` で分岐した宣言。例: `Illuminate\Testing\ParallelRunner`）は、パス順で最初の宣言を使う。
- 継承したメソッドやトレイトから取り込んだメソッドは、宣言した型にだけ記録する（その型のメソッドとして展開しない）。

## 公開 API の範囲

- 対象: `src/Illuminate/` 配下の `public` メソッド（クラス・インターフェース・トレイト・enum）。
- `@internal` が付いたものは記録はするが、サイトでは既定で隠す。
- マジックメソッド経由の API（Facade の `@method`、`__call` で転送されるメソッド）は、まず docblock の `@method` を記録するところまで
  （`methods.source = docblock`）。転送先の解決は後のマイルストーンで扱う。

## 使う Laravel の機能

| 機能 | 使いどころ |
| --- | --- |
| Eloquent | 上記のモデル。リレーション・スコープ・enum キャスト |
| マイグレーション | SQLite のスキーマ |
| キュー（`Bus::batch` / `chain`） | 1 リリースの解析を「取得 → 解析 → 差分」のジョブに分ける。失敗時のリトライ・バックオフ |
| ジョブミドルウェア | GitHub API へのレート制限（`RateLimited`）、二重実行の防止（`WithoutOverlapping`） |
| HTTP クライアント | GitHub API・tarball の取得。リトライ、`Http::fake` によるテスト |
| Process / Storage | 展開・一時ファイルの管理 |
| スケジューラ | 新しいタグの定期チェック（VPS 移行後に効く） |
| イベント / 通知 | 「新しいリリースを解析した」「型の足りないメソッドが増えた」を自分宛てに通知（任意） |
| Artisan コマンド | `since:sync`、`since:build`、`since:diff {from} {to}` など |
| Blade コンポーネント | 静的サイトのページ |
| テスト | PHPUnit（Pest は入れない）。`Http::fake`・`Bus::fake`・`Queue::fake` |
| Larastan | レベル `max`・ベースラインなしで自分のコードを検査する（`phpstan.neon`、CI で実行） |

## ソース解析の方針

- 取得したソースは **信頼できない入力** として扱う。`require` / `include` / `eval` / オートロードで読み込まない。
  `nikic/php-parser` で構文木にして読むだけにする。docblock は `phpstan/phpdoc-parser` で読む。
- テストのフィクスチャ（`tests/Fixtures/`）も同じく構文解析の入力としてだけ使う。Composer のクラスマップ（`exclude-from-classmap`）と
  Larastan（`excludePaths`）の対象から外している。書かれたとおりの docblock を解析の入力にするので、Pint（`pint.json` の `exclude`）でも整形しない。
- tarball の展開はパストラバーサル（`../` や絶対パス、シンボリックリンク）を拒否する。
- 型の文字列表現は、ネイティブ型と docblock の型を別々に持つ（Larastan のスタブとの比較では docblock 側が要る）。

## 静的サイトの出力（案）

```
dist/
  index.html                      検索ページ（search.json をブラウザで絞り込む）
  search.json                     全シンボルの軽量インデックス
  releases/{version}/index.html   そのリリースで追加・削除・変更されたもの
  types/{fqcn}/index.html         クラスごとのメソッド一覧と各メソッドの「いつから」
  _headers                        セキュリティヘッダー（CSP など。security.md 参照）
```

- サイトにユーザー入力を受け取るフォームやサーバー処理はない。検索はブラウザ内で完結する。

## 未決事項

- 実行のたびに全リリースを解析し直すか、前回の SQLite を Actions のキャッシュやリリース資産で引き継ぐか。
  まずは全件を作り直す方式で計測し、時間がかかりすぎる場合に引き継ぎ方式へ移る。
- Larastan のスタブとの突き合わせ（どのスタブのどのメソッドが `model-property` を持つか）の取得方法。
- 対象パッケージを `laravel/framework` 以外（Livewire など）に広げるか。
