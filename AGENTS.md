# laravel-since contributor guidance

Laravel フレームワークの公開 API をリリースごとに解析し、「いつから存在するか・いつ変わったか」を
逆引きできる静的サイトを作るプロジェクト。人間・Claude・Codex 共通の作業規則。

## 最初に読むもの

- [docs/context.md](docs/context.md): 経緯・目的・決めたこと（なぜこうなっているか）
- [docs/architecture.md](docs/architecture.md): 構成・データモデル・使う Laravel の機能
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
node scripts/devenv.mjs compose exec app composer audit       # 依存の脆弱性確認
node --test scripts/devenv.test.mjs                           # devenv のテスト（ホストの Node）
```

## 規則

### 設計
- ドキュメントと実装を一致させる。設計を変えたら同じ PR で `docs/` を更新する。
- 処理は Artisan コマンド・ジョブ・スケジューラという普通の Laravel の作りで書く（将来 VPS に移しても同じ動きにするため）。
- 列挙値は backed enum + Eloquent の enum キャストで扱う。

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
