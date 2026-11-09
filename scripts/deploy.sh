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
  resources/views/bplus-ops/pos-preparation.blade.php
  resources/views/bplus-ops/pos-workbench.blade.php
)
for retired in "${RETIRED_PATHS[@]}"; do
  if [[ -e "$APP_DIR/$retired" ]]; then
    echo "Removing retired path: $retired"
    rm -rf -- "${APP_DIR:?}/$retired"
  fi
done
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
php artisan migrate --force
php artisan storage:link || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
# deploy รันด้วย root: ไฟล์ cache ที่เพิ่งสร้างจึงเป็นของ root แล้ว www-data แตะไม่ได้
# (touch(): Utime failed → หน้าเว็บ 500 หลัง deploy) คืนเจ้าของให้ www-data ทุกครั้ง
if [[ "$(id -u)" == "0" ]] && id www-data >/dev/null 2>&1; then
  chown -R www-data:www-data storage bootstrap/cache
fi
php artisan erp:health
