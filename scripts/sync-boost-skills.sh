#!/usr/bin/env bash
#
# Re-sync Laravel Boost skills into WorkBuddy's project skill directory.
#
# Why this exists
# ---------------
# `php artisan boost:install` / `boost:update` write skills to `.agents/skills/`.
# WorkBuddy does NOT scan that directory — its own source notes that `.agents`
# skills are "neither listed in the settings page nor scanned by the agent
# runtime" (deferred, issue #73690). WorkBuddy reads project skills from
# `.workbuddy-ai/skills/` instead, so the skills have to be mirrored across.
#
# Run this after `php artisan boost:update`, which rewrites `.agents/skills`
# and would otherwise leave the mirror stale.
#
# `impeccable` is deliberately excluded: a different copy is already installed
# at ~/.workbuddy-ai/skills/impeccable and the two would collide by name.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$PROJECT_ROOT/.agents/skills"
DEST="$PROJECT_ROOT/.workbuddy-ai/skills"

SKILLS=(
  cashier-paddle-development
  debug-using-debugbar
  grill-me
  infer-conventions
  laravel-best-practices
  medialibrary-development
  tailwindcss-development
  testing-best-practices
  truss-schema
)

if [ ! -d "$SRC" ]; then
  echo "error: $SRC not found — is Laravel Boost installed?" >&2
  exit 1
fi

mkdir -p "$DEST"

for skill in "${SKILLS[@]}"; do
  if [ ! -f "$SRC/$skill/SKILL.md" ]; then
    echo "skip: $skill (no SKILL.md in .agents/skills)" >&2
    continue
  fi
  rm -rf "$DEST/$skill"
  cp -R "$SRC/$skill" "$DEST/$skill"
  echo "synced: $skill"
done

# Anything new Boost added that this script does not know about yet.
while IFS= read -r dir; do
  skill="$(basename "$dir")"
  case "$skill" in
    impeccable) continue ;; # intentionally not mirrored — see header
    .*) continue ;;
  esac
  managed=0
  for known in "${SKILLS[@]}"; do
    [ "$known" = "$skill" ] && managed=1 && break
  done
  if [ "$managed" -eq 0 ]; then
    echo "note: unmirrored skill '$skill' exists in .agents/skills — add it to SKILLS[] to pick it up" >&2
  fi
done < <(find "$SRC" -mindepth 1 -maxdepth 1 -type d)
