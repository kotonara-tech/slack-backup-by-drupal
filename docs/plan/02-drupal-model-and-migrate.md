# 02. データモデル ＋ Migrate 取込

canonical JSON を Drupal エンティティへ取り込む。担当：`drupal-backend-implementer`。ADR-0006。

## ToDo
- [x] (medium) config install：`slack_message` content type（field_body・field_channel・field_slack_user(_id)・field_slack_ts(一意)・field_posted_at・field_reactions＋field_reaction_total・field_attachments・thread/subtype/edited/reply_count）を定義
- [x] (medium) config install：`slack_channels` / `slack_users` taxonomy ＋ user/file 参照（email 除外）
- [x] (small) process plugin：Slack `ts`（epoch.micro）→ Drupal datetime 変換（`slack_timestamp_to_datetime`）
- [x] (small) process plugin：title 導出（`slack_message_title`）・reactions JSON 化（`slack_reactions_to_json`）※ channel slug は不要だった（taxonomy term + field_slack_channel_id で十分）
- [x] (medium) custom source（`slack_canonical_messages`/`slack_canonical_files`）＋ migrate_plus YAML：canonical JSON → `slack_message`、`ids=slack_ts`、channels→users→files→messages の依存順、thread_broadcast dedup
- [x] (medium) 冪等性＋track_changes＋privacy（status←channel_type）：`migrate:import` を 2 回実行して件数不変（実 export 916 node・published 141/unpublished 775）
- [x] ADR-0006 / ADR-0013 を `accepted` に（documentor）。spec: `docs/spec/data-model.md`・`docs/spec/migrate.md` 新設

## 完了の定義
- [x] `drush migrate:import --group=slack_portal` で canonical JSON が `slack_message` ノードになる。再実行で重複しない（実 export で確認済み）。

## 次
→ 03-jsonapi-and-search.md（Search API は published のみ索引＝ADR-0013 申し送り）
