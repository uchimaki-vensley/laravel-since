# laravel-since contributor guidance

Laravel フレームワークの公開 API をリリースごとに解析し、「いつから存在するか・いつ変わったか」を
逆引きできる静的サイトを作るプロジェクト。人間・Claude・Codex 共通の作業規則。

## 最初に読むもの

- [docs/context.md](docs/context.md): 経緯・目的・決めたこと（なぜこうなっているか）
- [docs/architecture.md](docs/architecture.md): 構成・データモデル・使う Laravel の機能
- [guideline.md](guideline.md): Onion Architecture の実装・レビュー基準（実装・レビュー前に必ず読む）
- [docs/infrastructure.md](docs/infrastructure.md): GitHub Actions / Cloudflare Pages の構成とシークレット
- [docs/security.md](docs/security.md): 脅威モデルと守るべき規則（**必ず守る**）

## 技術スタック

- Laravel 13 / PHP 8.5 / SQLite
- ソース解析: `nikic/php-parser`（解析対象のコードは実行しない）
- 配信: 静的 HTML / JSON を Cloudflare Pages へ（GitHub Actions から Direct Upload）

## コマンド

ホストに PHP は入れない。すべて Docker の開発環境で実行する。素の `docker compose` は使わない。

```sh
node scripts/devenv.mjs up                                    # 起動
node scripts/devenv.mjs info                                  # 割当ポート
node scripts/devenv.mjs compose exec app php artisan test     # テスト
node scripts/devenv.mjs compose exec app vendor/bin/pint      # 整形
node scripts/devenv.mjs compose exec app composer analyse     # 静的解析（Larastan / PHPStan、レベル max）
node scripts/devenv.mjs compose exec app composer audit       # 依存の脆弱性確認
node --test scripts/devenv.test.mjs                           # devenv のテスト（ホストの Node）
```

## 規則

### 設計
- Onion Architecture の層・依存方向・配置は guideline.md に従い、実装時とレビュー時に人が確認する。Application Service は Application にインターフェースと実装を置き、Repository は Application にインターフェース、Infrastructure に実装を置く。app/Providers で実装を結び付ける。自動検査は PHP と autoload の一般的な誤りを対象とし、アーキテクチャの適合は判定しない。境界を変える場合は関連ドキュメントと振る舞いのテストを更新する。
- ドキュメントと実装を一致させる。設計を変えたら同じ PR で `docs/` を更新する。
- 処理は Artisan コマンド・ジョブ・スケジューラという普通の Laravel の作りで書く（将来 VPS に移しても同じ動きにするため）。
- 列挙値は backed enum + Eloquent の enum キャストで扱う。

### 静的解析とテスト
- 静的解析は Larastan をレベル `max` で使う（設定は `phpstan.neon`）。CI の `test` ジョブで実行され、エラーがあれば失敗する。
- ベースライン（`phpstan-baseline.neon`）は作らない。エラーは `@phpstan-ignore` で黙らせず、コードか型の記述を直す。
  どうしても無視が必要なら `@phpstan-ignore <識別子> (理由)` の形で、識別子と理由を書く。
- テストは **PHPUnit** で書く（依存を増やさないため。Pest は入れない）。
  アプリを起動するテストは `tests/Feature` に置いて `Tests\TestCase` を、起動しないテストは `tests/Unit` に置いて
  `PHPUnit\Framework\TestCase` を継承する。メソッド名は `test_` で始める。外部 HTTP は `Http::fake` で止める。

### セキュリティ（詳細は docs/security.md）
- 解析対象のソースを `require` / `include` / `eval` / オートロードで読み込まない。構文解析だけにする。
- tarball などの展開では、パストラバーサル（`../`、絶対パス、シンボリックリンク）を拒否する。
- シークレット・トークン・`.env` の中身をコミット・Issue・PR・ログに書かない。
- ワークフローの追加・変更では、Action をコミット SHA で固定し、`permissions:` を最小にし、
  `pull_request_target` / `workflow_run` を使わない。
- Blade では `{!! !!}` を使わない（使う場合は理由をコメントに書く）。外部 CDN のスクリプトを読み込まない。
- 新しい依存を追加するときは、必要性とメンテナンス状況を PR 本文に書く。

### `scripts/devenv.mjs`
- `scripts/devenv.mjs` / `scripts/devenv.test.mjs` はテンプレート（basically-development-flows リポジトリ）が正本。このリポジトリで編集しない。

## Review guidelines（Codex 向け）

- docs/security.md の規則に反する変更は P1 として指摘する。
- 解析対象のソースを実行しうるコード、展開時のパス検証漏れは P0 として指摘する。
- ドキュメント（docs/）と実装の食い違いを指摘する。
