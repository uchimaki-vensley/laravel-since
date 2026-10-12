# Onion Architecture 実装ガイドライン

この文書は実装とレビューの共通基準です。着手前に読み、レビューでは末尾のチェック項目を使ってください。設計を変える場合は、この文書と docs/architecture.md を同じ PR で更新します。

## 層と依存方向

~~~text
Presentation → Application → Domain
Infrastructure → Application / Domain
Composition Root → 各層の具体実装を接続
~~~

依存は内側へ向けます。Domain と Application は外側の技術詳細を知りません。

| 層 | 配置・名前空間 | 責務 | 依存してよい層 |
| --- | --- | --- | --- |
| Domain | app/Domain、App\Domain | 公開 API の概念・値・ルール | Domain のみ |
| Application | app/Application、App\Application | 取得・解析・差分・出力のユースケースとポート | Application、Domain |
| Infrastructure | app/Infrastructure、App\Infrastructure | Eloquent / SQLite、HTTP、ファイル操作、parser などの技術アダプター | Infrastructure、Application、Domain |
| Presentation | app/Http、app/Console、app/Jobs、routes | Laravel の入口。入力を受け、Application のユースケースを呼ぶ | Presentation、Application |
| Composition Root | app/Providers | Laravel の依存登録・具体実装の接続のみ | 登録に必要な各層 |

Presentation から Infrastructure の具体クラスを直接使わないでください。必要な処理は Application のユースケースに置き、Infrastructure の実装は Application が所有するポートを通して接続します。

## 実装ルール

- Domain には Laravel、Eloquent、HTTP、ファイルシステム、parser などの外部技術を持ち込みません。Laravel helper や Facade も使いません。
- Application はユースケースとポートを持ち、Laravel や Infrastructure の具体クラスに依存しません。
- Infrastructure に Eloquent モデルと外部 I/O の実装を置きます。Eloquent モデルは永続化レコードとして扱い、ドメインルールを置きません。
- Presentation は入力・出力と Laravel のライフサイクルを扱い、ユースケースの詳細や永続化を実装しません。
- app/Providers は Composition Root として依存を登録する場所です。ユースケースや業務ルールは置きません。
- Repository や Use Case の抽象は、具体的な機能で境界が必要になった時に追加します。先回りした空の interface や層は作りません。
- 既存の責務を別の層へ移す場合は、呼び出し元、テスト、docs/architecture.md、ArchitectureTest を合わせて更新します。

## 実装時の確認

1. 変更するコードを上表の層に分類し、ファイルと名前空間を対応させます。
2. 依存先が許可方向にあることを確認します。内側のコードから外側のクラスを直接呼ぶ必要がある場合は、Application にポートを置き、Infrastructure で実装します。
3. 層の境界をまたぐ振る舞いには、呼び出し元と結果を検証するテストを追加します。
4. 新しい層・名前空間・依存パターンを導入したら、ArchitectureTest の対象と違反例テストを更新します。
5. 実装と設計が変わった場合は docs/architecture.md も更新します。

## レビュー時の確認

- 変更された各クラスの層と責務が上表に合っているか。
- Domain / Application が Laravel、Infrastructure、外部 I/O に依存していないか。
- Presentation が Eloquent モデルや Infrastructure の具体クラスを直接参照していないか。
- ポートが利用側の Application に置かれ、Infrastructure が実装しているか。
- ArchitectureTest が追加・変更された依存を検出し、違反時にファイル・行・依存先を示すか。
- テストが依存方向の正常例・違反例を検査し、CI の既存テストジョブで実行されるか。
- ドキュメントと実装に食い違いがないか。

## 強制と違反の直し方

tests/Unit/ArchitectureTest.php がファイル配置と宣言 namespace の一致、層ごとの名前空間依存、Domain / Application からの Laravel helper・Facade alias・代表的な PHP ファイルシステム関数とクラスの利用を検査します。検査失敗には対象ファイル、行、依存先が表示されます。CI は既存の PHPUnit 実行でこの検査を行います。Larastan と Pint は層間依存を保証しません。

違反した場合は、外側の技術処理を Infrastructure へ移すか、Application が所有するポートを介して接続します。依存ルール自体を変える必要がある場合は、先にこの文書・docs/architecture.md・ArchitectureTest を一緒に更新してください。違反を無視する設定やコメントで検査を迂回しないでください。
