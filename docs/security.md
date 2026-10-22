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

- 使ってよい Action: GitHub 製（`actions/*`）と、許可リストに明記したもの（`shivammathur/setup-php`、`cloudflare/wrangler-action`）だけ。
  `cloudflare/wrangler-action` は許可リストにあるが使っていない（wrangler は `deploy/package-lock.json` で版とハッシュを固定して npm から入れる）
- Action は **コミット SHA で固定** する（タグは書き換えられるため）。コメントにバージョンを書く。
  リポジトリ設定 `sha_pinning_required` により、SHA で固定していない Action を含むワークフローは GitHub 側で実行が拒否される
- `GITHUB_TOKEN` の既定権限は **読み取りのみ**。必要なジョブだけ `permissions:` で広げる
- Actions による PR の作成・承認を禁止
- フォークからの PR のワークフロー実行は、外部の人については **すべて承認制**
- `actions/checkout` は `persist-credentials: false` で使う
- `pull_request_target` と `workflow_run` は使わない（使う必要が出たら、この文書に理由と対策を書いてから）

### 環境（Environments）

- `production`: デプロイ用。シークレットはここにだけ置く。デプロイ可能なブランチは `main` のみ
- `deploy.yml` は、取得したソースの解析とサイトの生成（`build`）と、Cloudflare へのアップロード（`deploy`）を別のジョブにし、
  `production` を使うのは `deploy` だけにする。信頼できない入力（tarball）や依存パッケージを扱うジョブからはシークレットが読めない。
  `deploy` ジョブは `npm ci --ignore-scripts` で wrangler を入れ、インストールスクリプトを実行しない

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

- `dist/_headers` で次のヘッダーを付ける（`since:build` が `resources/site/_headers` を出力する）
  - `Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'`
    （自サイトのスクリプト・スタイル・画像と、`search.json` の取得だけを許可。インラインスクリプト・インラインスタイルは使わない）
  - `X-Content-Type-Options: nosniff`、`Referrer-Policy: strict-origin-when-cross-origin`、`X-Frame-Options: DENY`
- 外部の CDN からスクリプト・スタイル・フォントを読み込まない（必要なものは `resources/site/assets` に置いて同梱する）
- Blade の出力は常にエスケープする（`{!! !!}` を使わない。使う場合は理由をコメントに書く）。
  解析したソース由来の文字列（型・既定値・docblock）も `{{ }}` で出す
- 検索（`assets/search.js`）は結果を `textContent` で組み立て、`innerHTML` を使わない
- 型名から作るページのパスは、PHP の識別子（ASCII）を `\` でつないだ名前に限る。それ以外の名前はページにせず警告を出す。
  書き込みは出力ディレクトリの中の相対パス（`.` で始まるセグメント・空のセグメント・バックスラッシュを含まないもの）に限り、既存のファイルを上書きしない
  （実装は `App\Application\Site\SitePath`、`App\Infrastructure\Site\LocalSiteFiles`）

## 取得したソースの扱い

`since:sync` が取得する tarball は信頼できない入力として扱う（実装は `App\Infrastructure\Archives\SafeTarGzExtractor`）。

- 取得: codeload.github.com だけから取り、リダイレクトに従わない。ダウンロードは `since.sources.max_download_bytes`（既定 50MB）を超えた時点で打ち切る。
  接続・応答のタイムアウトを設定する。GitHub のトークンは tarball の取得に送らない
- 展開: PHP の `PharData` やシステムの `tar` を使わず、tar のヘッダーを 1 エントリずつ検証してから書き出す
  - 通常ファイルとディレクトリだけを書き出す。シンボリックリンク・ハードリンク・デバイス・FIFO・GNU 拡張は拒否する
  - パスは、絶対パス、`..` / `.` / 空のセグメント、バックスラッシュ、ドライブレター、制御文字を含むものを拒否する。
    pax 拡張ヘッダーは `path` だけを使い、`size` / `linkpath` は拒否する
  - 1 ファイル（既定 5MB）・展開後の合計（既定 200MB、gzip 爆弾対策）・エントリ数（既定 20,000）に上限を置く
  - ヘッダーのチェックサムを検証する。既存のパスへの書き込み（重複エントリ）は拒否する
  - 空の一時ディレクトリに展開し終えてから公開先へ rename する。失敗したら一時ディレクトリごと消す
- 展開したファイルは文字列として読み、`nikic/php-parser`（コード）と `phpstan/phpdoc-parser`（docblock）で構文として解析するだけにする。
  `require` / `include` / `eval` / オートロードしない。解析時もシンボリックリンクはたどらない（実装は `App\Infrastructure\PhpSource\PhpSourceParser`）

## 開発時のルール（エージェントを含む）

- シークレット・トークン・`.env` の中身をコミット、Issue、PR、ログに書かない
- 新しい依存を追加するときは、メンテナンス状況とダウンロード数を確認し、PR 本文に理由を書く
- ワークフローを追加・変更するときは、この文書の「Actions」の規則をすべて満たすこと
- 解析対象のソースを実行するコード（`require`、`include`、`eval`、オートロードへの追加）を書かない

### 多言語出力

言語のパス接頭辞は `SiteLocale` enum の固定値 `en` / `ja` / `hu` のみ。
翻訳と検索用メッセージは Blade の `{{ }}` でエスケープし、JS には `data-*` 属性で渡す。
言語切替も同梱した外部 JS で行い、CSP のインラインスクリプト禁止を維持する。
`<link>` は同じサイトの相対パスだけにし、絶対 URL は canonical と hreflang（`since.site.url` で始まるもの）に限る。
`<script>` / `<img>` の `src` も同じサイトのパスだけにする（`//` で始まる他サイトの URL も不可）。どちらも `BuildCommandTest` で全ページを検査する。
