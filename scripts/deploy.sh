#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/jeterp}"
BRANCH="${DEPLOY_BRANCH:-main}"

cd "$APP_DIR"
php artisan down --retry=30
trap 'php artisan up' EXIT
if [[ "${SKIP_GIT:-0}" != "1" ]]; then
  git fetch --prune origin "$BRANCH"
  git checkout "$BRANCH"
  git pull --ff-only origin "$BRANCH"
fi
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan erp:health
