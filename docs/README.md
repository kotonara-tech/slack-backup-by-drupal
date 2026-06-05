# docs

ドキュメントは [Diátaxis](https://diataxis.fr/) に沿って 4 種を**分離**して置く。`documentor` エージェントが維持する。

| ディレクトリ | 種別 | 目的 | 例 |
|------|------|------|----|
| `tutorials/` | チュートリアル | 学習（手を動かして体験） | ローカル環境を立てて初回エクスポートするまで |
| `how-to/` | ハウツー | 目的達成（手順） | 新しい検索 facet を追加する手順 |
| `reference/` | リファレンス | 事実（API/設定/コマンド） | JSON:API エンドポイント、Drush コマンド一覧 |
| `explanation/` | 解説 | なぜ（背景・トレードオフ） | なぜヘッドレス Drupal + Next.js か |

その他：
- `adr/` … Architecture Decision Records（MADR）。意思決定の不変履歴（**なぜ**そう決めたか）。
- `spec/` … 確定した実装仕様（**何が**確定したか）。canonical JSON スキーマ・ingest パイプライン・credential トポロジ・ポータル API 契約など。コードはこの spec/ADR を参照する。
- `plan/` … ロードマップ（TDD マイルストーン）。

混在させない（例：reference に解説を混ぜない）。各種別の使い分けは Diátaxis を参照。
