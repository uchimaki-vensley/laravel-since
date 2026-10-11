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
| `deploy.yml` | 定期実行（1 日 1 回程度）/ 手動実行。**main のみ** | `contents: read` のみ | `production` 環境の `CLOUDFLARE_API_TOKEN` / `CLOUDFLARE_ACCOUNT_ID` | 未作成（Issue で作る） |

### deploy.yml の流れ（予定）

1. main をチェックアウト（`persist-credentials: false`）
2. PHP をセットアップし、`composer install --no-dev`
3. `php artisan migrate` → `php artisan since:sync` → `php artisan since:build`
4. `wrangler pages deploy dist/ --project-name=laravel-since`（Direct Upload）

- Cloudflare Pages の **Git 連携は使わない**。Git 連携だと PR ごとにプレビューのビルドが走るため、
  ビルドの実行とデプロイの権限を GitHub Actions の `production` 環境に一本化する。
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

1. Cloudflare アカウントで Pages プロジェクト `laravel-since` を **Direct Upload** で作る
2. API トークンを作る: 権限「Account > Cloudflare Pages > Edit」、対象アカウントを限定、有効期限を設定
3. GitHub の Settings > Environments > `production` にシークレットとして登録する
4. Cloudflare アカウントの 2 要素認証を有効にする

## ローカル開発環境

- `compose.yaml` の `app` サービス（`docker/Dockerfile`: PHP 8.5 CLI + Composer）。
- 起動時に `docker/dev-start.sh` が `composer install` → `.env` 作成 → `key:generate` → `migrate` → `php artisan serve` を行う。
- ポートは `127.0.0.1` にだけ公開する（同じネットワークの他の端末からは見えない）。
- 割当ポートは `node scripts/devenv.mjs info` で確認する。
