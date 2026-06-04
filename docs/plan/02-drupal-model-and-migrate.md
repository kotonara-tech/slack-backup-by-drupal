# 02. データモデル ＋ Migrate 取込

canonical JSON を Drupal エンティティへ取り込む。担当：`drupal-backend-implementer`。ADR-0006。

## ToDo
- [ ] (medium) config install：`slack_message` content type（body・field_channel・field_slack_user_id・field_slack_ts(一意)・field_posted_at・field_reactions・添付）を定義
- [ ] (medium) config install：`slack_channels` taxonomy ＋ user/file 参照
- [ ] (small) process plugin：Slack `ts`（epoch.micro）→ Drupal datetime 変換
- [ ] (small) process plugin：channel 名 → slug
- [ ] (medium) migrate_plus YAML：canonical JSON(JSON source) → `slack_message`、`ids=slack_ts`、channels→users→messages→files の依存順
- [ ] (medium) 冪等性：`migrate:import` を 2 回実行して件数不変
- [ ] ADR-0006 を `accepted` に（documentor）

## 完了の定義
- `make migrate` で canonical JSON が `slack_message` ノードになる。再実行で重複しない。

## 次
→ 03-jsonapi-and-search.md
