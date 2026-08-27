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
: "${SSH_IDENTITY_FILE:=${HOME}/.ssh/id_ed25519_erppop}"
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

SSH_OPTIONS=(-p "$SSH_PORT")
RSYNC_RSH="ssh -p ${SSH_PORT}"
if [[ -f "$SSH_IDENTITY_FILE" ]]; then
  SSH_OPTIONS+=(-i "$SSH_IDENTITY_FILE" -o IdentitiesOnly=yes)
  RSYNC_RSH+=" -i ${SSH_IDENTITY_FILE} -o IdentitiesOnly=yes"
fi

# --no-owner/--no-group: ห้ามยกเจ้าของไฟล์จากเครื่อง dev ขึ้นไป ครั้งหนึ่งเคยทำให้
# bootstrap/cache เปลี่ยนเจ้าของเป็น uid ของ macOS แล้ว www-data เขียนไม่ได้ เว็บล่มทั้งระบบ
rsync -az --delete --no-owner --no-group \
  -e "$RSYNC_RSH" \
  --exclude='.git/' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='auth.json' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --exclude='package-lock.json' \
  --exclude='.claude/' \
  --exclude='.codex/' \
  --exclude='bootstrap/cache/' \
  --exclude='database/*.sqlite*' \
  --exclude='.phpunit.result.cache' \
  --exclude='.DS_Store' \
  --exclude='public/downloads/' \
  --exclude='public/storage' \
  --exclude='storage/' \
  ./ "${SSH_USER}@${SSH_HOST}:${REMOTE_PATH}/"

ssh "${SSH_OPTIONS[@]}" "${SSH_USER}@${SSH_HOST}" \
  "cd '${REMOTE_PATH}' \
  && if [ '${RUN_COMPOSER_INSTALL}' = '1' ]; then composer install --no-dev --optimize-autoloader; fi \
  && if [ '${RUN_NPM_BUILD}' = '1' ]; then npm ci && npm run build; fi \
  && php artisan optimize:clear \
  && php artisan config:clear \
  && php artisan route:clear \
  && php artisan view:clear \
  && chown -R www-data:www-data storage bootstrap/cache \
  && php artisan migrate --force"

echo "Deploy complete."
