#!/usr/bin/env bash
# TDD ガード: production src の変更には対応するテストの随伴を要求する（pre-commit）。
# 回避が必要な正当なケースは SKIP_TDD_CHECK=1 で明示的に無効化できる（濫用禁止）。
set -euo pipefail

if [[ "${SKIP_TDD_CHECK:-0}" == "1" ]]; then
  echo "[check_tdd] SKIP_TDD_CHECK=1 のためスキップ"
  exit 0
fi

# ステージ済みファイル（追加/変更/コピー/リネーム）
mapfile -t staged < <(git diff --cached --name-only --diff-filter=ACMR)
[[ ${#staged[@]} -eq 0 ]] && exit 0

is_test() {
  local f="$1"
  [[ "$f" == *"/tests/"* ]] && return 0           # Drupal: web/modules/custom/*/tests/...
  [[ "$f" == *.test.ts || "$f" == *.test.tsx ]] && return 0   # frontend Vitest
  [[ "$f" == *.spec.ts || "$f" == *.spec.tsx ]] && return 0   # frontend Playwright/spec
  [[ "$f" == frontend/tests/* ]] && return 0
  return 1
}

is_prod_src() {
  local f="$1"
  # Drupal module の production PHP（tests 配下を除く）
  if [[ "$f" == web/modules/custom/*/src/* && "$f" != *"/tests/"* ]]; then
    case "$f" in *.php) return 0;; esac
  fi
  # frontend の production TS/TSX（tests/ と *.test/*.spec を除く）
  if [[ "$f" == frontend/* && "$f" != frontend/tests/* ]]; then
    case "$f" in
      *.test.ts|*.test.tsx|*.spec.ts|*.spec.tsx) return 1;;
      *.ts|*.tsx) [[ "$f" == frontend/app/* || "$f" == frontend/lib/* || "$f" == frontend/components/* ]] && return 0;;
    esac
  fi
  return 1
}

prod_changed=0
test_changed=0
for f in "${staged[@]}"; do
  if is_test "$f"; then test_changed=1; fi
  if is_prod_src "$f"; then prod_changed=1; fi
done

if [[ $prod_changed -eq 1 && $test_changed -eq 0 ]]; then
  echo "❌ [check_tdd] production の src を変更していますが、テストの変更が含まれていません。"
  echo "   TDD: 失敗するテストを先に書いてください（.claude/rules/tdd-enforcement.md）。"
  echo "   どうしても正当な例外なら SKIP_TDD_CHECK=1 を付けてコミットしてください（濫用禁止）。"
  exit 1
fi

echo "[check_tdd] OK"
