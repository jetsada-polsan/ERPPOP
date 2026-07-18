#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ -f deploy/env.local ]]; then
  # shellcheck disable=SC1091
  source deploy/env.local
fi

: "${SSH_USER:=root}"
: "${SSH_HOST:=27.254.143.219}"
: "${SSH_PORT:=22}"
: "${REMOTE_PATH:=/var/www/jeterp}"
: "${RUN_COMPOSER_INSTALL:=0}"
: "${RUN_NPM_BUILD:=0}"

if [[ -z "$SSH_HOST" || -z "$REMOTE_PATH" ]]; then
  echo "Missing SSH_HOST or REMOTE_PATH" >&2
  exit 1
fi

if ! git diff --quiet; then
  echo "Working tree has uncommitted changes. Commit before deploy." >&2
  exit 1
fi

echo "Deploying $(git rev-parse --short HEAD) to ${SSH_USER}@${SSH_HOST}:${REMOTE_PATH}"

rsync -az --delete \
  -e "ssh -p ${SSH_PORT}" \
  --exclude='.git/' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='auth.json' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='public/build/' \
  --exclude='public/downloads/' \
  --exclude='public/storage' \
  --exclude='storage/_bak/' \
  --exclude='storage/deploy-backups/' \
  --exclude='storage/framework/.config/' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  --exclude='storage/logs/*' \
  ./ "${SSH_USER}@${SSH_HOST}:${REMOTE_PATH}/"

ssh -p "$SSH_PORT" "${SSH_USER}@${SSH_HOST}" \
  "cd '${REMOTE_PATH}' \
  && if [ '${RUN_COMPOSER_INSTALL}' = '1' ]; then composer install --no-dev --optimize-autoloader; fi \
  && if [ '${RUN_NPM_BUILD}' = '1' ]; then npm ci && npm run build; fi \
  && php artisan optimize:clear \
  && php artisan config:clear \
  && php artisan route:clear \
  && php artisan view:clear \
  && php artisan migrate --force"

echo "Deploy complete."
