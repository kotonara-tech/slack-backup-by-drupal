#!/usr/bin/env bash
# Slack アーカイブ / トークン / 機密ファイルの誤コミットを検出する（pre-commit）。
# detect-secrets と二重化した安全柵。
set -euo pipefail

mapfile -t staged < <(git diff --cached --name-only --diff-filter=ACMR)
[[ ${#staged[@]} -eq 0 ]] && exit 0

fail=0

# 1) パスでの禁止: slack_archive / .env(非example) / settings.local.php / files 配下
for f in "${staged[@]}"; do
  case "$f" in
    *slack_archive/*|*/files/slack_archive*)
      echo "❌ [no-archive] Slack アーカイブをコミットしようとしています: $f"; fail=1;;
    .env|*/.env)
      echo "❌ [no-archive] .env をコミットしようとしています: $f"; fail=1;;
    .env.*|*/.env.*)
      [[ "$f" == *.env.example ]] || { echo "❌ [no-archive] env ファイルをコミットしようとしています: $f"; fail=1; }
      ;;
    *settings.local.php)
      echo "❌ [no-archive] settings.local.php をコミットしようとしています: $f"; fail=1;;
  esac
done

# 2) 内容での禁止: Slack トークン文字列（pragma: allowlist secret 行は除外）
#    パターン: xoxp-/xoxb-/xoxa-/xoxr- に続く実トークン形（プレースホルダ REPLACE-ME は除外）
token_re='xox[bpars]-[0-9A-Za-z-]{8,}'
for f in "${staged[@]}"; do
  [[ -f "$f" ]] || continue
  if git show ":$f" 2>/dev/null \
      | grep -nE "$token_re" \
      | grep -v 'pragma: allowlist secret' \
      | grep -vi 'REPLACE-ME' \
      | grep -q .; then
    echo "❌ [no-archive] Slack トークンらしき文字列が含まれています: $f"
    fail=1
  fi
done

if [[ $fail -ne 0 ]]; then
  echo "   → .claude/rules/secrets-and-pii.md を参照。トークンは Key/env で、アーカイブは絶対にコミットしない。"
  exit 1
fi

echo "[no-archive] OK"
