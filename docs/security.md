# セキュリティ

このリポジトリは public だが、開発者はオーナー（@uchimaki-vensley）1 人だけ。
**オーナー以外が、コード・CI・配信先（Cloudflare Pages）に変更を加えられないこと** を最優先にする。

設定を変えたら、この文書も同じ PR で更新すること。脆弱性の報告方法は [SECURITY.md](../SECURITY.md)。

## 守るもの

| 資産 | 侵害されると |
| --- | --- |
| `main` ブランチ | 悪意あるコードが定期実行のワークフローで動き、配信サイトが改ざんされる |
| `CLOUDFLARE_API_TOKEN` | Cloudflare Pages のサイトを書き換えられる |
| GitHub Actions の実行環境 | 上記シークレットの窃取、リポジトリへの書き込み |
| 配信サイトの閲覧者 | 改ざんされた JavaScript の実行（XSS など） |
| 依存パッケージ | サプライチェーン経由で上記すべて |

個人情報は扱わない（ログイン・フォーム・ユーザーデータがない）。

## 想定する攻撃者と対策

| 攻撃者 | できること | 対策 |
| --- | --- | --- |
| 外部の人（public なので誰でも） | Issue・PR・コメントの作成、フォークからの PR | 書き込み権限はオーナーのみ。フォークからの PR のワークフローは **毎回オーナーの承認が必要**。PR のワークフローは読み取り専用トークンでシークレットなし |
| 悪意ある PR の内容 | ワークフローファイルの改変、テストに紛れたコード | `pull_request_target` を使わない。シークレットは main 限定の環境にしかない。マージできるのはオーナーだけなので、main に入る前にオーナーが差分を読む |
| 侵害された依存パッケージ / Action | CI 上で任意コード実行 | Action はコミット SHA で固定し、許可リスト以外の Action を禁止。`composer.lock` を固定し `composer audit` を CI で実行。Dependabot で更新を PR として受け取り、内容を見てマージ |
| 解析対象の Laravel のソース | 解析時の任意コード実行 | ソースは実行せず `nikic/php-parser` で構文として読むだけ。展開時のパストラバーサルを拒否 |
| シークレットの誤コミット | トークン漏洩 | シークレットスキャンとプッシュ保護を有効化。`.env` は `.gitignore` 済み |
| オーナーのアカウント乗っ取り | すべて | GitHub・Cloudflare とも 2 要素認証（オーナー側の運用で担保） |

## リポジトリの設定（GitHub）

API で設定済み。状態は `gh api repos/uchimaki-vensley/laravel-since ...` で確認できる。

### ブランチの保護（ルールセット `protect-main`、対象: 既定ブランチ）

- 削除の禁止、強制プッシュの禁止
- PR 経由でのみ変更可（直接 push 不可）。承認数は 0（1 人開発で、自分の PR は自分で承認できないため）。
  マージには書き込み権限が要り、それを持つのはオーナーだけなので、外部の PR はオーナーがマージしない限り main に入らない
- ステータスチェック `test`（`ci.yml`）の成功が必須
- バイパス: リポジトリ管理者のみ、**PR 経由のときだけ**（緊急時に CI 失敗のままマージする余地を残す。直接 push はできない）

### Actions

- 使ってよい Action: GitHub 製（`actions/*`）と、許可リストに明記したもの（`shivammathur/setup-php`、`cloudflare/wrangler-action`）だけ
- Action は **コミット SHA で固定** する（タグは書き換えられるため）。コメントにバージョンを書く。
  リポジトリ設定 `sha_pinning_required` により、SHA で固定していない Action を含むワークフローは GitHub 側で実行が拒否される
- `GITHUB_TOKEN` の既定権限は **読み取りのみ**。必要なジョブだけ `permissions:` で広げる
- Actions による PR の作成・承認を禁止
- フォークからの PR のワークフロー実行は、外部の人については **すべて承認制**
- `actions/checkout` は `persist-credentials: false` で使う
- `pull_request_target` と `workflow_run` は使わない（使う必要が出たら、この文書に理由と対策を書いてから）

### 環境（Environments）

- `production`: デプロイ用。シークレットはここにだけ置く。デプロイ可能なブランチは `main` のみ

### コードセキュリティ

- シークレットスキャン、プッシュ保護: 有効
- Dependabot アラート、Dependabot セキュリティアップデート: 有効
- Dependabot バージョンアップデート（`.github/dependabot.yml`）: GitHub Actions・Composer・npm・Docker
- CodeQL（default setup）: GitHub Actions のワークフローを解析（PHP は CodeQL の対象外）
- 非公開の脆弱性報告（Private vulnerability reporting）: 有効

### その他

- Wiki・Projects: 無効（使わないものは閉じる）
- マージ方法: squash のみ。マージ後にブランチを自動削除

## 配信サイト（Cloudflare Pages）

- `dist/_headers` で次のヘッダーを付ける（`since:build` が出力する）
  - `Content-Security-Policy`: 自サイトのスクリプトとスタイルだけを許可（インラインスクリプト禁止）
  - `X-Content-Type-Options: nosniff`、`Referrer-Policy: strict-origin-when-cross-origin`、`X-Frame-Options: DENY`
- 外部の CDN からスクリプトを読み込まない（必要なものはビルドに同梱する）
- Blade の出力は常にエスケープする（`{!! !!}` を使わない。使う場合は理由をコメントに書く）

## 開発時のルール（エージェントを含む）

- シークレット・トークン・`.env` の中身をコミット、Issue、PR、ログに書かない
- 新しい依存を追加するときは、メンテナンス状況とダウンロード数を確認し、PR 本文に理由を書く
- ワークフローを追加・変更するときは、この文書の「Actions」の規則をすべて満たすこと
- 解析対象のソースを実行するコード（`require`、`include`、`eval`、オートロードへの追加）を書かない
