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
# rsync ไม่มี --delete ไฟล์ที่เลิกใช้จึงค้างบน server — ลบเฉพาะรายการที่ระบุชื่อชัดเจนเท่านั้น
RETIRED_PATHS=(
  public/pos-desktop-preview
  resources/views/pos/compare.blade.php
  resources/views/pos/browser.blade.php
  resources/views/erp-mockup
  resources/views/greenfield
  resources/js/greenfield
  resources/css/app.css
  app/Http/Controllers/ErpMockupController.php
  app/Support/ErpMockData.php
)
for retired in "${RETIRED_PATHS[@]}"; do
  if [[ -e "$APP_DIR/$retired" ]]; then
    echo "Removing retired path: $retired"
    rm -rf -- "${APP_DIR:?}/$retired"
  fi
done
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan erp:health
