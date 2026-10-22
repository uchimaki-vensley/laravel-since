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
 (tarball)      │    │      RecordReleaseChanges 直前のリリースと比較 → 変更履歴                                │
                │    └─ 完了イベント ─▶ 通知（自分宛て。任意）                                                  │
                │                                                                                             │
                │  php artisan since:build   Blade で言語ごとの静的 HTML と共通の JSON を dist/ に書き出す    │
                │                                                                                             │
                │  wrangler pages deploy dist/   ─────────────────────────────────────────────▶ Cloudflare Pages │
                └─────────────────────────────────────────────────────────────────────────────────────────────┘
```

- Laravel は **バッチとして** 動く。Web サーバー（`php artisan serve`）は開発時のプレビューにだけ使う。
- 本番に常時動くプロセスはない。キューは 1 回の実行の中で処理しきる（`database` ドライバ + `queue:work --stop-when-empty`）。
  後回しにされたジョブ（レート制限・重なり防止）だけが残ると `--stop-when-empty` は終わるので、`scripts/generate-data.sh` が
  `jobs` テーブルが空になるまで繰り返す（deploy.yml と手元で同じものを使う）。
- ワーカーは 1 つで動かす。ジョブの `timeout`（解析 600 秒、記録 1800 秒）が `database` ドライバの `retry_after`（既定 90 秒）より長いので、
  ワーカーを増やす場合は先に `DB_QUEUE_RETRY_AFTER` を最長の `timeout` より長くする（実行中のジョブを別のワーカーが取り直さないように）。
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

Issue #6（変更履歴の記録と `since:diff`）の配置:

| 層 | クラス | 役割 |
| --- | --- | --- |
| Domain | `App\Domain\Changes\ApiDiff` / `MethodChange` / `SymbolName` | 2 つのスナップショットの比較規則と、リリースをまたいだメソッドの同一性 |
| Application | `App\Application\Changes\ReleaseChangeRecorder` / `ReleaseComparer`（実装は `*Service`）、`MethodSignatureFormatter` | 変更履歴の記録・2 リリースの比較のユースケース、シグネチャの文字列表現 |
| Application（ポート） | `ReleaseChangeRepository`、`ApiSnapshotRepository::load()`、`ReleaseRepository::findByVersion()` | 記録の状態と変更履歴の保存、スナップショットの読み出し |
| Infrastructure | `Persistence\Eloquent\Repositories\EloquentReleaseChangeRepository` | Eloquent |
| Presentation | `App\Console\Commands\DiffCommand`（`since:diff`）、`App\Jobs\RecordReleaseChanges` | 入口とジョブ |

Issue #7（静的サイトの出力）の配置:

| 層 | クラス | 役割 |
| --- | --- | --- |
| Application | `App\Application\Site\SiteBuilder`（実装は `SiteBuilderService`）、`SitePath`、`SiteLocale`、`SignatureFormatter`、`Pages\*` | サイトを作るユースケース。言語付きの URL とファイルの置き場所、件数の表示形式、シグネチャの表示、ページの内容 |
| Application（ポート） | `SiteCatalog` / `SiteRenderer` / `SiteOutput` / `SiteFiles` | DB の読み出し・HTML への変換・出力先の置き換え・ファイルの書き込み |
| Infrastructure | `Persistence\Eloquent\Repositories\EloquentSiteCatalog`、`Site\BladeSiteRenderer`、`Site\LocalSiteOutput` / `LocalSiteFiles` | Eloquent、Blade（`resources/views/site`）、ローカルのファイル |
| Presentation | `App\Console\Commands\BuildCommand`（`since:build`） | 入口 |

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
  snapshot_revision       is_internal              docblock_return_type     is_variadic / by_ref
  changes_basis           is_deprecated            signature_hash           docblock_type
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
- 「いつから存在するか」は `symbol_changes` の最後の `added` を見る。最初のリリース（`since.minimum_version`）の `added` は、
  それより前を解析していないので「そのリリース以前からある」の意味になる（サイトでは「v11.0.0 以前から」と表示する）。
- シグネチャの変化は `signature_hash`（static か、戻り値・引数の名前・型・既定値・可変長・参照渡し、docblock の型）の違いで判定する。
- `releases.snapshot_revision` はスナップショットを保存するたびに増える。`releases.changes_basis` はそのリリースの変更履歴を
  どのスナップショット同士の比較で記録したか（`{直前のリリース ID}@{その版}:{このリリースの版}`、最初のリリースは `baseline:{版}`）。
- `types.kind` / `methods.visibility` / `methods.source` / `symbol_changes.kind` は Domain の backed enum を Eloquent の enum キャストで保存する。
- 一意キー: `releases(package, version)`、`types(release_id, fqcn)`、`methods(type_id, name)`、
  `parameters(method_id, position)`、`symbols(fqcn, member)`、`symbol_changes(symbol_id, release_id, kind)`。
- データ量（2026-10 の実測）: `laravel/framework` 1 リリースあたり型 1,200〜1,700、メソッド 1 万〜1.3 万（うち `@method` 約 2,600）、
  引数約 1.7 万行。11.0 以降のリリース数 × それ。スナップショットが大きくなりすぎる場合は「変化があったときだけ行を持つ」形に切り替える。

## リリースの同期とソースの取得（`since:sync`）

1. GitHub API（`/repos/{package}/tags`）からタグを 100 件ずつ全ページ取得する。
2. `v{major}.{minor}.{patch}` 形式の安定版のうち、`since.minimum_version`（既定 `11.0.0`）以上を `releases` に登録する。
   登録済みのものは `insertOrIgnore` で無視するので、何度実行しても重複しない。
3. 未解析（`parsed_at` が null）のリリースごとに `FetchReleaseSource` → `ParseReleaseSource` → `RecordReleaseChanges` のチェーンを作り、
   1 つの `Bus::batch` で投入する（`allowFailures`: 1 リリースの失敗で他を止めない）。未解析のリリースがなければ `RecordReleaseChanges` だけを投入する。
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

## 変更履歴の記録（`RecordReleaseChanges`）と `since:diff`

1. 登録済みのリリースをバージョン順に並べ、解析済みのリリースを直前のリリース（未解析を含めた登録済みの中で 1 つ前）と比べる。
2. 比較は `ApiDiff`（Domain）で行う。型の完全修飾名とメソッド名の組（大文字小文字は区別しない）を同じメソッドとみなし、
   - 追加（`added`）: 直前になく、このリリースにある。`after` にシグネチャを残す
   - 削除（`removed`）: 直前にあり、このリリースにない（型ごと消えた場合や public でなくなった場合も含む）。`before` に残す
   - シグネチャ変更（`signature_changed`）: `signature_hash` が違う。`before` / `after` の両方を残す
   - 非推奨化（`deprecated`）: 直前では非推奨でなく、このリリースで非推奨（`@deprecated` / `#[\Deprecated]`）になった。`before` / `after` の両方を残す
   を記録する。シグネチャ変更と非推奨化は同じリリースで両方起こりうる。
3. 変化のあったメソッドの `symbols` を（なければ）作り、そのリリースの `symbol_changes` を置き換えて `changes_basis` を更新する（1 トランザクション）。
4. 最初のリリースは空のスナップショットと比べ、含まれるメソッドすべてを `added` として記録する（意味は上の「データモデル」を参照）。

- 記録の対象・やり直しは `changes_basis` で決める。直前のリリースが変わった（間のリリースが後から登録された）とき、
  どちらかのスナップショットが再解析で置き換わったときは値が変わるので記録し直す。同じなら何もしない（何度動かしても同じ結果）。
- 直前のリリースが未解析なら、そのリリースは記録しない（間を飛ばして比べると、変化の起きたリリースを誤るため）。直前が解析されたあとの記録で記録する。
- `since:sync` は各リリースのチェーンの最後に `RecordReleaseChanges` を置く。記録はパッケージ全体を見るので、解析が終わる順番に
  関係なく、最後に動いた記録ジョブがすべてを記録する。重なった記録ジョブは `WithoutOverlapping` で待たせ、捨てない。
  未解析のリリースがないときも `RecordReleaseChanges` だけを投入し、前回記録できなかった分を記録する。
- バージョン順は版番号の比較だけで決める（ブランチの分岐は見ない）。例えば 12.x の修正リリースが 13.0.0 より後に出ても、直前は版番号で 1 つ前のもの。
- `before` / `after` の JSON:

  ```json
  {
    "signature": "incrementEach(array $columns, array $extra = []) [@param array<string, float|int|numeric-string> $columns, @param array<string, mixed> $extra, @return int]",
    "name": "incrementEach", "source": "declared", "is_static": false,
    "return_type": null, "docblock_return_type": "int",
    "parameters": [{"name": "columns", "type": "array", "default_value": null, "is_variadic": false, "by_ref": false,
                    "docblock_type": "array<string, float|int|numeric-string>"}, ...],
    "is_internal": false, "is_deprecated": false
  }
  ```

  `signature` は `MethodSignatureFormatter` の形式。宣言メソッドはネイティブの型で書き、docblock の型を末尾の `[...]` にまとめる。
  docblock の `@method` は `@method static make(string|null $name = null): static` のように docblock の型で書く。
- `php artisan since:diff {from} {to}`（例: `since:diff 13.2.0 13.3.0`）は、解析済みの 2 リリースのスナップショットを直接比べて表示する
  （記録とは別に計算する。隣り合わないリリース同士や、逆向きも比べられる）。`v` 付きのタグ名も受け付ける。

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
| Larastan | レベル `max`・ベースラインなしで自分のコードを検査する（`phpstan.neon`、CI で実行）。モデルの `casts()` を読ませて enum キャストの型を推論させる（`parseModelCastsMethod`） |

## ソース解析の方針

- 取得したソースは **信頼できない入力** として扱う。`require` / `include` / `eval` / オートロードで読み込まない。
  `nikic/php-parser` で構文木にして読むだけにする。docblock は `phpstan/phpdoc-parser` で読む。
- テストのフィクスチャ（`tests/Fixtures/`）も同じく構文解析の入力としてだけ使う。Composer のクラスマップ（`exclude-from-classmap`）と
  Larastan（`excludePaths`）の対象から外している。書かれたとおりの docblock を解析の入力にするので、Pint（`pint.json` の `exclude`）でも整形しない。
- tarball の展開はパストラバーサル（`../` や絶対パス、シンボリックリンク）を拒否する。
- 型の文字列表現は、ネイティブ型と docblock の型を別々に持つ（Larastan のスタブとの比較では docblock 側が要る）。

## 静的サイトの出力（`since:build`）

```
dist/
  index.html                       静的プレビュー用の英語トップ（本番は `_redirects` で /en/ に 302 転送）
  {locale}/index.html              検索ページ（search.json をブラウザで絞り込む）とリリース一覧
  search.json                      型とメソッドの軽量な一覧
  {locale}/releases/{version}/index.html    そのリリースで追加・削除・変更・非推奨になったもの
  {locale}/types/{名前空間を / で区切った型名}/index.html
                                   型ごとのメソッド一覧と、各メソッドの「いつから」と変更履歴
                                   （例: en/types/Illuminate/Database/Eloquent/Builder/）
  assets/                          site.css・search.js・language.js・favicon.svg（resources/site からそのまま写す）
  _redirects                       トップ（302）と多言語化前の URL（301）の転送（Cloudflare Pages）
  _headers                         セキュリティヘッダー（CSP など。security.md 参照）
  .since-site                      since:build が書いた出力であることの印
```

- 対象は解析済みで変更履歴も記録した（`parsed_at` と `changes_basis` がある）リリース。型のページは **最新のリリースのスナップショット** と **全リリースの `symbol_changes`** から作る。
  最新のリリースにない型・メソッドも、変更履歴があればページに載せる（「v… で削除」と表示）。
- 「いつから」は、そのメソッドの最後の `added` のリリース。解析した最も古いリリース（既定 11.0.0）と同じなら「v… 以前から」と表示する
  （それより前のリリースは解析していないため）。`added` がなければ「追加の記録なし」。
- 起点のリリースのページは、全メソッドが `added` になるので件数だけ出して一覧を省く。
- 変更履歴の変化前後は、`symbol_changes.before` / `after` の `signature`（`MethodSignatureFormatter` の 1 行）をそのまま表示する。
- シグネチャは 1 行で表示する（例: `static slug(string $title, string $separator = '-'): string`）。宣言されたメソッドはネイティブの型、
  `@method` は docblock の型を使い、宣言されたメソッドの docblock の型は `@param` / `@return` として別に表示する。
- `@internal` のメソッドは型のページで畳んで表示し、検索では既定で除く（チェックボックスで含められる）。
- `search.json` は `{"latest": "13.36.0", "types": [[型名, 印]], "members": [[型の添字, メソッド名, いつから, 印]]}`。
  印はビットの組み合わせ（1: `@internal`、2: 非推奨、4: 最新のリリースにない、8: `@method`、16: 「以前から」）。
  13.36.0 の 1 リリースで約 510KB（gzip で約 80KB）。
- 出力は `since.site.path`（環境変数 `SINCE_SITE_PATH`、既定 `dist/`）。同じ親ディレクトリの一時ディレクトリに書き終えてから rename で置き換える。
  誤った設定で無関係なディレクトリを消さないよう、既存の出力先は空か `.since-site` がある場合だけ置き換える。
- サイトにユーザー入力を受け取るフォームやサーバー処理はない。検索はブラウザ内で完結する。
- 多言語化前の実測（13.35.0 / 13.36.0 の 2 リリース）: 1,646 型・13,068 メソッドで 1,655 ファイル・約 26MB、メモリのピーク約 75MB。
  スナップショットと変更履歴は 200 件（変更履歴は 2,000 件）ずつ読み、Eloquent のモデルをまとめて持たない。
  ページの内容は言語によらず 1 回だけ作るので、言語を増やしても増えるのは描画と書き出しだけで、メモリはほぼ変わらない。

## 静的サイトの多言語化

`since:build` は `SiteLocale`（Application の backed enum）に定義した英語 `en`、日本語 `ja`、
ハンガリー語 `hu` の HTML をそれぞれ `dist/{locale}/` に生成する。`search.json` と `assets/` は共通で一度だけ出力する。

- ページの内容（`IndexPage` / `ReleasePage` / `TypePage`）は言語によらないので、`SiteBuilderService` が 1 回だけ作り、
  言語ごとに `SiteRenderer` で描画して書き出す。並べ替えや集計を言語の数だけ繰り返さない。
- ページの場所とリンク先は値オブジェクト `SitePath`（Application）で持つ。URL は `SitePath::url(SiteLocale)`、
  出力ファイルは `SitePath::file(SiteLocale)` でだけ作り、どちらも言語の接頭辞を必ず含む。
  - `SitePath` は文字列に変換できないので、Blade で `{{ $item->path }}` のように言語なしで出力するとビルドが例外で止まる
    （接頭辞の付け忘れが、誤ったリンクのまま公開されない）。Blade ではレンダラーが渡す `$siteUrl($path)` を使う。
  - 各ページは自分の `SitePath` を持ち（`TypePage::$path`、`ReleasePage::$release->path`、トップは `SitePath::home()`）、
    書き出し先と canonical・hreflang・言語リンクは同じ値から作る。
  - 型名をパスにする検証（PHP の識別子だけ）は `SitePath::type()` が行い、パスにできない型は `null` になってページを作らない。
- `SiteRenderer` の各メソッドは `SiteLocale` を受け取り、`BladeSiteRenderer` が翻訳の locale を描画中だけ変える
  （例外時も元に戻す）。Domain / Application は Laravel の翻訳機能に依存しない。

翻訳は `lang/{en,ja,hu}/site.php`。文章全体をキーに対応させ、バージョン・件数はプレースホルダーで渡す。
全言語で同じキーとプレースホルダーを持つことをテストし、fallback には頼らない。

- 件数の表示は `SiteLocale::formatNumber()` に従う。区切り文字と区切り始める桁数は CLDR に合わせる
  （en / ja は 4 桁から `,`、hu は 5 桁から改行しない空白。例: `1646`、`12 345`）。
- 検索 JS は、翻訳・区切りの規則・言語付きのサイトの基準パスを HTML のエスケープ済み `data-*` 属性から読み、
  ページと同じ規則で件数を表示し、`textContent` で結果を作る。

各ページには、現在のページに対応する言語リンク、`html lang`、canonical、相互の hreflang と英語の `x-default` を出す。
`assets/language.js` は言語リンクの `href` を、常に現在の検索語（`?q=`）とフラグメント付きに保つ
（読み込み時、`hashchange`・`popstate`、検索 JS が URL を書き換えたときの `since:urlchange`）。
クリックだけでなく、中クリック・新しいタブで開く・リンクのコピーでも引き継がれる。
メタデータの公開 origin は `since.site.url`（`SINCE_SITE_URL`、既定 `https://laravel-since.pages.dev`）。
独自ドメインに変えるときはビルドの環境で設定する。

`resources/site/_redirects` は Cloudflare Pages の転送規則。

- `/` は `/en/` に 302 で転送する。既定の言語を後で変えられるよう、ブラウザに長くキャッシュされる 301 にはしない。
- 多言語化前の `/types/*` と `/releases/*` は、日本語の `/ja/…` に 301 で転送する（中身が日本語だったため）。
- `_redirects` がない静的プレビュー用に、`dist/index.html` にも英語のトップを出す（canonical は `/en/`）。
- `since:build` が表示する件数は言語の数を掛けず、元のリリース・型・メソッドの数を返す。
- Cloudflare Pages の Free プランは 1 デプロイ 20,000 ファイルまで。HTML は「(型の数 + リリースの数 + 1) × 言語の数」になるので、
  言語や対象パッケージを増やすときはこの上限を確認する。

## 定期実行の所要時間と、毎回作り直す方式（決定）

`deploy.yml` は実行のたびに空の SQLite から全リリースを取得・解析し直す。前回の SQLite は引き継がない。

2026-10-16 に、起点 11.0.0 から全件を作り直して計測した（開発用の Docker、ワーカー 1 つ、GitHub API は未認証）。

| 項目 | 結果 |
| --- | --- |
| 対象リリース | 276（11.0.0〜13.36.0） |
| `scripts/generate-data.sh`（同期・取得・解析・記録） | 13 分 50 秒（失敗ジョブ 0） |
| `since:build` | 6 秒、最大メモリ約 110MB |
| SQLite | 約 1.0GB（types 38 万、methods 312 万、parameters 393 万、symbols 1.3 万、symbol_changes 1.9 万） |
| 展開したソース | 約 3.3GB |
| `dist/`（多言語化前の実測） | 1,937 ファイル、約 41MB（`search.json` 約 504KB、gzip 後約 82KB） |
| `dist/`（3 言語の見込み。未計測） | HTML が約 3 倍になり、約 5,800 ファイル・約 120MB。`since:build` は 20 秒前後 |

- 解析は 1 リリース 1〜2 秒、取得はレート制限（毎分 30 件）が律速になる。記録ジョブは各チェーンの末尾で動くが、
  記録済みなら数ミリ秒で終わり、新しく記録するときも 13〜17 秒。
- Actions の標準ランナーは手元より遅いと見込んでも 30 分前後で、1 日 1 回の実行には十分。引き継ぎ方式は、
  キャッシュの容量（10GB）や改ざんされた中間データを信頼する経路が増えるので採らない。
- 次のどれかに当たったら引き継ぎ方式（リリースごとのスナップショットを成果物として保存するなど）を検討する:
  `build` ジョブが 60 分を超える、ランナーのディスク（展開したソース）が足りなくなる、SQLite がさらに大きくなる。

## 未決事項

- Larastan のスタブとの突き合わせ（どのスタブのどのメソッドが `model-property` を持つか）の取得方法。
- 対象パッケージを `laravel/framework` 以外（Livewire など）に広げるか。
