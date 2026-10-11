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

## データモデル（案）

```
releases            1 ──< types              1 ──< methods            1 ──< parameters
  id                      id                       id                       id
  package                 release_id               type_id                  method_id
  version (13.3.0)        fqcn                     name                     position
  tag (v13.3.0)           kind (class|interface    visibility               name
  released_at                   |trait|enum)       is_static                type (文字列表現)
  parsed_at               file_path                return_type              default_value (文字列表現)
                                                   docblock_return_type     is_variadic / by_ref
                                                   signature_hash           docblock_type

symbols（リリースをまたいだ同一性）           symbol_changes（差分の結果）
  id                                            id
  fqcn + member（例: Illuminate\...\Builder::incrementEach）   symbol_id
                                                release_id（変化が起きたリリース）
                                                kind (added|removed|signature_changed|deprecated)
                                                before / after（JSON: 変化前後のシグネチャ）
```

- `types` / `methods` / `parameters` はリリースごとのスナップショット。`symbols` はリリースをまたいで同じメソッドを指す ID。
- 「いつから存在するか」は `symbol_changes` の `added` を見る。シグネチャの比較は `signature_hash` で絞り込んでから詳細を比べる。
- `kind` などの列挙値は PHP の backed enum + Eloquent の enum キャストで扱う。
- データ量の見積もり（要検証）: `laravel/framework` 1 リリースあたり数千メソッド。11.0 以降のリリース数 × それ。
  スナップショットが大きくなりすぎる場合は「変化があったときだけ行を持つ」形に切り替える。

## 公開 API の範囲

- 対象: `src/Illuminate/` 配下の `public` メソッド（クラス・インターフェース・トレイト・enum）。
- `@internal` が付いたものは記録はするが、サイトでは既定で隠す。
- マジックメソッド経由の API（Facade の `@method`、`__call` で転送されるメソッド）は、まず docblock の `@method` を記録するところまで。
  転送先の解決は後のマイルストーンで扱う。

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
| テスト | Pest または PHPUnit。`Http::fake`・`Bus::fake`・`Queue::fake` |
| Larastan | 最大レベルで自分のコードを検査する |

## ソース解析の方針

- 取得したソースは **信頼できない入力** として扱う。`require` / `include` / `eval` / オートロードで読み込まない。
  `nikic/php-parser` で構文木にして読むだけにする。
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
