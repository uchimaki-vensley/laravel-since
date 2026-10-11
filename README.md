# laravel-since

> Find out since which Laravel version a framework method exists — and when its signature changed.

Laravel フレームワークの公開 API（クラス・メソッド・引数）をリリースごとに解析し、
「このメソッドはどのバージョンから使えるか」「いつシグネチャが変わったか」を逆引きできる静的サイトを作るプロジェクト。

**Status:** 立ち上げ直後（実装前）。進め方は [Issues](https://github.com/uchimaki-vensley/laravel-since/issues) を参照。

This project is not affiliated with or endorsed by Laravel.

## 何ができるようになるか

- メソッド名から、そのメソッドが追加された Laravel のバージョンを引ける（例: `Eloquent\Builder::incrementEach()` → 13.3.0）
- 引数の型・既定値・戻り値などのシグネチャ変更の履歴を追える
- 2 つのバージョン間で追加・削除・変更された API を一覧できる
- （後のマイルストーン）[Larastan](https://github.com/larastan/larastan) のスタブで、カラム名を受け取るのに `model-property` 型が付いていないメソッドを洗い出せる

## 構成（概要）

サーバーを持たない無料構成。Laravel は Web サーバーとしてではなく、**データを作るバッチ**として GitHub Actions 上で動く。

```
GitHub Actions（定期実行）
  └─ Laravel（artisan コマンド）
       ├─ GitHub API で新しいリリースタグを検出
       ├─ キューで解析（ソース取得 → nikic/php-parser で構文解析 → 前バージョンとの差分）
       ├─ SQLite に保存
       └─ 静的な HTML / JSON を書き出す
            └─ Cloudflare Pages に配置
```

詳しくは次のドキュメントを参照。

| ドキュメント | 内容 |
| --- | --- |
| [docs/context.md](docs/context.md) | このプロジェクトを始めた経緯・目的・既存サービスとの違い・決めたこと（引き継ぎ用） |
| [docs/architecture.md](docs/architecture.md) | アプリケーションの構成・データモデル・処理の流れ・使う Laravel の機能 |
| [docs/infrastructure.md](docs/infrastructure.md) | GitHub Actions と Cloudflare Pages の構成・シークレット・費用 |
| [docs/security.md](docs/security.md) | 脅威モデルと、リポジトリ・CI・配信の各層で取っている対策 |
| [SECURITY.md](SECURITY.md) | 脆弱性の報告方法 |

## 開発環境

ホストに PHP は不要。Docker で worktree ごとに独立した環境を立ち上げる（ポートは自動割当）。

```sh
node scripts/devenv.mjs up        # 起動（composer install → migrate → php artisan serve）
node scripts/devenv.mjs info      # 割当ポートと URL を表示
node scripts/devenv.mjs compose exec app php artisan test
node scripts/devenv.mjs compose exec app vendor/bin/pint
node scripts/devenv.mjs stop      # 停止（ポートは保持）
node scripts/devenv.mjs down      # 削除（ボリュームとポートも解放）
```

## License

[MIT](LICENSE)
