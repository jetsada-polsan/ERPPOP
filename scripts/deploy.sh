#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/jeterp}"
BRANCH="${DEPLOY_BRANCH:-main}"

cd "$APP_DIR"
# workflow Deploy ERP ปิดเว็บไว้ก่อน rsync แล้ว — ถ้าอยู่ในโหมดปิดอยู่แล้วไม่ต้องสั่งซ้ำ
if [[ -f storage/framework/down ]]; then
  echo "Site is already in maintenance mode."
else
  php artisan down --retry=30
fi

# เปิดเว็บกลับ (artisan up) เฉพาะเมื่อทุกขั้นตอนข้างล่างผ่าน รวมถึง erp:health
# ถ้าขั้นไหนพัง เว็บจะ "ปิดค้างไว้โดยตั้งใจ": rsync ทับโค้ดใหม่ไปแล้วและไม่มี rollback
# การเปิดกลับเท่ากับให้ผู้ใช้ใช้โค้ดใหม่บน schema/vendor ที่ยังไม่พร้อม — เสี่ยงข้อมูลเสีย
# วิธีกู้: แก้สาเหตุแล้ว deploy ใหม่ หรือตรวจเว็บเองแล้วสั่ง `php artisan up` ใน $APP_DIR
DEPLOY_OK=0
finish() {
  local status=$?
  trap - EXIT
  if [[ "$status" == "0" && "$DEPLOY_OK" == "1" ]]; then
    if php artisan up; then
      echo "Deploy finished, site is up."
      exit 0
    fi
    echo "::error::Deploy finished but 'php artisan up' failed - the site is still in maintenance mode."
    exit 1
  fi
  [[ "$status" == "0" ]] && status=1
  echo "::error::DEPLOY FAILED (exit $status) - the site was LEFT IN MAINTENANCE MODE on purpose. Fix the cause and redeploy, or check the site and run 'php artisan up' in $APP_DIR."
  echo "=================================================================="
  echo " DEPLOY FAILED - SITE IS STILL DOWN (maintenance mode)"
  echo " Recover: fix the error above and run Deploy ERP again, or"
  echo "          cd $APP_DIR && php artisan up   (only after checking)"
  echo "=================================================================="
  exit "$status"
}
trap finish EXIT
trap 'echo "deploy.sh: command failed at line $LINENO: $BASH_COMMAND" >&2' ERR

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
DEPLOY_OK=1
