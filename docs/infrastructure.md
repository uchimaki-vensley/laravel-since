# インフラ構成

経緯は [context.md](context.md)、セキュリティ対策の詳細は [security.md](security.md) を参照。

## 構成要素

| 要素 | 役割 | 費用 |
| --- | --- | --- |
| GitHub リポジトリ（public） | コード・Issue・CI | 無料 |
| GitHub Actions | CI（PR・main への push）と、データ生成・デプロイの定期実行 | public リポジトリの標準ランナーは無料 |
| Cloudflare Pages | 静的サイトの配信 | 無料プランの範囲 |
| ローカル（Docker） | 開発環境。worktree ごとに `scripts/devenv.mjs` で立ち上げる | ― |

VPS・常時動くサーバー・外部 DB は持たない（理由は context.md の 5 章）。

## ワークフロー

| ワークフロー | きっかけ | 権限 | シークレット | 状態 |
| --- | --- | --- | --- | --- |
| `ci.yml` | PR / main への push | `contents: read` のみ | 使わない | 作成済み |
| `deploy.yml` | 定期実行（毎日 03:17 UTC）/ 手動実行。**main のみ** | `contents: read` のみ | `deploy` ジョブだけが `production` 環境の `CLOUDFLARE_API_TOKEN` / `CLOUDFLARE_ACCOUNT_ID` を読む | 作成済み |

### deploy.yml の流れ

2 つのジョブに分け、解析対象のソースや依存パッケージを扱う `build` にはシークレットを渡さない。
どちらのジョブも `github.ref` が main でなければ動かない（手動実行でほかのブランチを選んでも動かない）。
`concurrency` で同時に 1 つしか動かさず、実行中のものは取り消さない。

**`build`**（environment なし、シークレットなし）

1. main をチェックアウト（`persist-credentials: false`）
2. PHP をセットアップし、`composer install --no-dev`
3. `scripts/generate-data.sh`: `migrate` → `since:sync` → キューが空になるまで `queue:work --stop-when-empty` を繰り返す
   - `queue:work --stop-when-empty` は、レート制限や重なり防止で後回しにされたジョブ（実行時刻が未来）だけが残ると終わってしまうので、
     `jobs` テーブルが空になるまで繰り返す
   - 失敗したジョブ（`failed_jobs`）が 1 件でもあればここで失敗させ、中途半端なデータでサイトを置き換えない（前回のサイトが残る）
   - 環境変数 `GITHUB_TOKEN` に Actions のトークン（読み取りのみ）を渡す。送り先は GitHub API（タグの一覧）だけ
   - `DB_QUEUE_RETRY_AFTER` を最長のジョブの `timeout`（1800 秒）より長くする
4. `php artisan since:build` で `dist/` を作り、成果物（artifact、保持 1 日）としてアップロード

**`deploy`**（environment `production`）

1. シークレットが登録されているかを確かめ、なければ名前だけをエラーに出して止まる（値は出さない）
2. `deploy/` だけをチェックアウトし、`deploy/package-lock.json` どおりに wrangler を入れる（`npm ci --ignore-scripts`）
3. `build` の成果物を取得し、`wrangler pages deploy --project-name=laravel-since --branch=main`（Direct Upload）

- Cloudflare Pages の **Git 連携は使わない**。Git 連携だと PR ごとにプレビューのビルドが走るため、
  ビルドの実行とデプロイの権限を GitHub Actions の `production` 環境に一本化する。
- wrangler は `cloudflare/wrangler-action` ではなく npm から入れる。action はロックファイルなしで wrangler を入れるため、
  推移的な依存まで版とハッシュを固定できる `deploy/package-lock.json` を使う。更新は Dependabot（npm、`/deploy`）が PR にする。
- GitHub API は Actions の `GITHUB_TOKEN`（読み取りのみ）で呼ぶ。未認証より緩いレート制限で済む。

## シークレットの管理

| シークレット | 置き場所 | 中身 |
| --- | --- | --- |
| `CLOUDFLARE_API_TOKEN` | GitHub の **environment `production`** のシークレット（リポジトリ全体のシークレットにはしない） | Cloudflare の API トークン。権限は「対象アカウントの Cloudflare Pages: Edit」だけ。有効期限を付ける |
| `CLOUDFLARE_ACCOUNT_ID` | 同上 | アカウント ID |

- `production` 環境は **main ブランチからのデプロイだけを許可** する（deployment branch policy）。
  PR やほかのブランチのワークフローからは、このシークレットを読めない。
- ローカルの `.env` は Git に入れない（`.gitignore` 済み）。`APP_KEY` は環境ごとに生成する。

## Cloudflare 側の準備（オーナーが手作業で行う）

1. Cloudflare アカウントで Pages プロジェクト `laravel-since` を **Direct Upload** で作る（本番ブランチは `main`）
2. API トークンを作る: 権限「Account > Cloudflare Pages > Edit」、対象アカウントを限定、有効期限を設定
3. GitHub の Settings > Environments > `production` にシークレット `CLOUDFLARE_API_TOKEN` と `CLOUDFLARE_ACCOUNT_ID` を登録する
4. Cloudflare アカウントの 2 要素認証を有効にする
5. Actions の「Deploy」を手動実行（Run workflow、ブランチは main）し、サイトが表示されることを確かめる

シークレットが未登録のうちは、定期実行の `deploy` ジョブが「Secret ... is not set」で失敗する（`build` は動く）。

## ローカル開発環境

- `compose.yaml` の `app` サービス（`docker/Dockerfile`: PHP 8.5 CLI + Composer）。
- 起動時に `docker/dev-start.sh` が `composer install` → `.env` 作成 → `key:generate` → `migrate` → `php artisan serve` を行う。
- ポートは `127.0.0.1` にだけ公開する（同じネットワークの他の端末からは見えない）。
- 割当ポートは `node scripts/devenv.mjs info` で確認する。
