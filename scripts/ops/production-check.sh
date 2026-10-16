#!/usr/bin/env bash
# ตรวจ production แบบไม่แก้ข้อมูลจริง: backup → restore drill ลงฐานแยก → เทียบจำนวนแถว → ลบฐาน drill
# พร้อมสุขภาพระบบและตัวเลขการใช้งานแต่ละโมดูล ผลออกเป็นบรรทัด "RESULT key=value" ให้ workflow เก็บ
set -uo pipefail
APP_DIR="${APP_DIR:-/var/www/jeterp}"
DRILL_DB="${DRILL_DB:-jeterp_restore_drill}"
cd "$APP_DIR"
r() { echo "RESULT $1=$2"; }

if [[ "$DRILL_DB" == "jeterp" ]]; then echo "หยุด: ชื่อฐาน drill ชนกับ production"; exit 1; fi

if php artisan erp:backup >/tmp/erp-backup.log 2>&1; then
  r backup ok
  r backup_file "$(ls -t storage/app/backups/erp-db-* 2>/dev/null | grep -v sha256 | head -1 | xargs -r basename)"
  r backup_size "$(ls -t storage/app/backups/erp-db-* 2>/dev/null | grep -v sha256 | head -1 | xargs -r du -h | cut -f1)"
else
  r backup failed; r backup_error "$(tail -1 /tmp/erp-backup.log | tr -d '\r' | cut -c1-200)"
fi

if ERP_RESTORE_DATABASE="$DRILL_DB" php artisan erp:restore-drill --execute >/tmp/erp-drill.log 2>&1; then
  r restore_drill ok
  php artisan tinker --execute="
    \$drill = array_merge(config('database.connections.pgsql'), ['database' => '$DRILL_DB']);
    config(['database.connections.drill' => \$drill]);
    \$diff = 0;
    foreach (['products','customers','pos_receipts','pos_receipt_items','documents','gl_journals','stock_balances','stock_movements'] as \$t) {
      \$a = DB::table(\$t)->count(); \$b = DB::connection('drill')->table(\$t)->count();
      if (abs(\$a - \$b) > 50) \$diff++;
      echo 'RESULT drill_'.\$t.'='.\$a.'/'.\$b.PHP_EOL;
    }
    echo 'RESULT drill_tables_mismatch='.\$diff.PHP_EOL;
  " </dev/null 2>&1 | grep '^RESULT'
elif [[ "$(id -u)" == "0" ]] && id postgres >/dev/null 2>&1; then
  # ผู้ใช้ของแอปไม่มีสิทธิ์สร้างฐาน: ให้ postgres (superuser ของเครื่อง) กู้ลงฐานแยกแทน
  latest="$(ls -t storage/app/backups/erp-db-* | grep -v sha256 | head -1)"
  if php artisan erp:restore-drill --backup="$latest" >/dev/null 2>&1 \
     && su postgres -c "dropdb --if-exists $DRILL_DB && createdb $DRILL_DB" \
     && gunzip -c "$latest" | su postgres -c "psql -q -v ON_ERROR_STOP=1 -d $DRILL_DB" >/tmp/erp-drill-psql.log 2>&1; then
    r restore_drill ok_via_postgres
    for t in products customers pos_receipts pos_receipt_items documents gl_journals stock_balances stock_movements; do
      a="$(su postgres -c "psql -tA -d jeterp -c 'select count(*) from $t'" 2>/dev/null)"
      b="$(su postgres -c "psql -tA -d $DRILL_DB -c 'select count(*) from $t'" 2>/dev/null)"
      DRILL_LINE="${DRILL_LINE:-}$t=$a/$b "
    done
    r drill_counts "$DRILL_LINE"
  else
    r restore_drill failed; r restore_error "$(tail -2 /tmp/erp-drill-psql.log 2>/dev/null | tr '\n\r' '  ' | cut -c1-300)"
  fi
  su postgres -c "dropdb --if-exists $DRILL_DB" && r drill_dropped_pg yes
else
  r restore_drill failed; r restore_error "$(grep -v '^\s*$' /tmp/erp-drill.log | tail -2 | tr '\n\r' '  ' | cut -c1-300)"; r whoami "$(id -un)"
fi
# ไม่ทิ้งสำเนาข้อมูลไว้บนเครื่อง production
php artisan tinker --execute="
  \$c = config('database.connections.pgsql');
  \$p = new Symfony\Component\Process\Process(['dropdb','--if-exists','-h',\$c['host'],'-p',(string)\$c['port'],'-U',env('ERP_BACKUP_DB_USERNAME') ?: \$c['username'],'$DRILL_DB'], null, ['PGPASSWORD'=>(string)(env('ERP_BACKUP_DB_PASSWORD') ?: \$c['password'])]);
  \$p->run(); echo 'RESULT drill_dropped='.(\$p->isSuccessful()?'yes':'no').PHP_EOL;
" </dev/null 2>&1 | grep '^RESULT'

if php artisan erp:health >/tmp/erp-health.log 2>&1; then r health ok; else r health failed; fi
grep -iE "fail|ล้มเหลว|warn" /tmp/erp-health.log | head -5 | while read -r line; do r health_note "$(echo "$line" | cut -c1-160)"; done
r migrations_pending "$(php artisan migrate:status 2>/dev/null | grep -c Pending)"

php artisan tinker --execute="
  \$rows = [];
  foreach (['documents','purchase_orders','purchase_order_receipts','payment_documents','customer_open_items','billing_notes','bank_statements','bank_reconciliations','stock_counts','stock_transfer_receipts','gl_journals','pos_receipts','pos_offline_sales','product_scan_logs','production_batches','fixed_assets','members'] as \$t) {
    \$rows[] = \$t.'='.(Schema::hasTable(\$t) ? DB::table(\$t)->count() : 'n/a');
  }
  echo 'RESULT rows='.implode(' ', \$rows).PHP_EOL;
  echo 'RESULT pos_receipts_today='.DB::table('pos_receipts')->whereDate('created_at', today())->count().PHP_EOL;
  echo 'RESULT offline_needs_review='.DB::table('pos_offline_sales')->where('status','needs_review')->count().PHP_EOL;
  echo 'RESULT desktop_rejects_logged_today='.DB::table('pos_offline_sales')->where('source','desktop')->whereDate('created_at', today())->count().PHP_EOL;
" </dev/null 2>&1 | grep '^RESULT'
