# Onion Architecture 実装ガイドライン

この文書は実装とレビューの共通基準です。着手前に読み、レビューでは末尾のチェック項目を使ってください。Onion Architecture への適合は実装時とレビュー時に人が確認します。設計を変える場合は、この文書と docs/architecture.md を同じ PR で更新します。

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
- Application の Service / Use Case はインターフェースと実装を持ちます。Presentation はインターフェースに依存し、実装は Application に置きます。
- Repository は Application にインターフェース（ポート）を置き、Application の Service はそのインターフェースに依存します。
- Infrastructure に Eloquent モデル、Repository の具体実装、外部 I/O の実装を置きます。Eloquent モデルは永続化レコードとして扱い、ドメインルールを置きません。
- Presentation は入力・出力と Laravel のライフサイクルを扱い、ユースケースの詳細や永続化を実装しません。
- app/Providers は Composition Root として Service / Repository のインターフェースと実装を結び付けます。ユースケースや業務ルールは置きません。
- Service / Repository は具体的な機能を実装するときに追加し、その時点で利用側にインターフェースを定義します。先回りした空の interface や層は作りません。
- 既存の責務を別の層へ移す場合は、呼び出し元、振る舞いのテスト、関連ドキュメントを合わせて更新します。

## 実装時の確認

1. 変更するコードを上表の層に分類し、ファイルと名前空間を対応させます。
2. 依存先が許可方向にあることを確認します。内側のコードから外側のクラスを直接呼ぶ必要がある場合は、Application にポートを置き、Infrastructure で実装します。
3. 層の境界をまたぐ振る舞いには、呼び出し元と結果を検証するテストを追加します。
4. 新しい層・名前空間・依存パターンを導入したら、ガイドとレビュー項目を見直します。
5. 実装と設計が変わった場合は docs/architecture.md も更新します。

## レビュー時の確認

- 変更された各クラスの層と責務が上表に合っているか。
- Domain / Application が Laravel、Infrastructure、外部 I/O に依存していないか。
- Presentation が Eloquent モデルや Infrastructure の具体クラスを直接参照していないか。
- Presentation が Application Service のインターフェースに依存し、Application の具体実装に結合していないか。
- Repository インターフェースが Application に置かれ、Infrastructure が実装しているか。Service / Repository の実装が対応するインターフェースを実装しているか。
- app/Providers がインターフェースを具体実装へ結び付けているか。
- 依存方向と各クラスの配置がこのガイドに沿っているか。
- 層をまたぐ振る舞いのテストが、期待する動作を検証しているか。
- ドキュメントと実装に食い違いがないか。

## 自動検査の範囲

CI の Larastan / PHPStan は解析対象 PHP の構文・型を検査し、構文エラーやクラス等の同一宣言内の重複メンバーも報告します。Composer の strict PSR-4 チェックは autoload 対象ファイルの namespace と配置の不整合を検出します。これらは PHP と autoload の一般的な誤りを対象にし、Onion Architecture の層や依存方向は判定しません。

層や依存方向に違反している場合は、外側の技術処理を Infrastructure へ移すか、Application が所有するポートを介して接続します。依存ルール自体を変える場合は、この文書と docs/architecture.md を一緒に更新してください。
