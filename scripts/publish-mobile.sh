#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ -f deploy/env.local ]]; then
  # shellcheck disable=SC1091
  source deploy/env.local
fi

: "${GIT_BRANCH:=main}"

MESSAGE="${1:-Update from Codex}"

if [[ -n "${GIT_REMOTE_URL:-}" ]] && ! git remote get-url origin >/dev/null 2>&1; then
  git remote add origin "$GIT_REMOTE_URL"
fi

if ! git remote get-url origin >/dev/null 2>&1; then
  echo "No GitHub remote configured. Set GIT_REMOTE_URL in deploy/env.local first." >&2
  exit 1
fi

if ! git diff --quiet || ! git diff --cached --quiet; then
  git add .
  git commit -m "$MESSAGE"
else
  echo "No local changes to commit."
fi

git branch -M "$GIT_BRANCH"
git push -u origin "$GIT_BRANCH"

"$ROOT_DIR/scripts/deploy-ssh.sh"
