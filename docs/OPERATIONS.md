# POPSTAR ERP Operations

## Environments

- Production uses its own database, `.env`, storage and domain.
- Staging must use a separate database, storage path and LINE test target. Never point staging at production data.
- Create GitHub environments named `staging` and `production`. Add `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS` secrets and an `APP_DIR` variable to each environment.
- `DEPLOY_KNOWN_HOSTS` holds the server's SSH host key line(s); the deploy refuses to connect without it (no `ssh-keyscan` trust-on-first-use). Build it from the server's own key files, with `<DEPLOY_HOST>` replaced by the exact value of the `DEPLOY_HOST` secret: `for f in /etc/ssh/ssh_host_*_key.pub; do echo "<DEPLOY_HOST> $(cut -d' ' -f1,2 "$f")"; done`
- Every other workflow that connects to `DEPLOY_HOST` over SSH (production-check, production-inspect, erp-reset-transactions, void-pos-test-sales and the other one-off server jobs) uses the same pinned key check and reads `DEPLOY_KNOWN_HOSTS` from the `production` environment. The POS build/publish workflows (pos-desktop-release, pos-python-windows-uat, jet-pos-python-uat) still use `ssh-keyscan`; the POS app is no longer built here, so they should not be run.
- Deploy ERP puts the site into maintenance mode and runs `php artisan erp:backup` before uploading (if the backup fails, the site goes back up untouched and the deploy stops). If upload, migrate or `erp:health` fails, the site stays in maintenance mode on purpose (there is no rollback); fix and redeploy, or check the site and run `php artisan up` in `APP_DIR`.
- Production deployment should require an environment reviewer in GitHub.

## Scheduled jobs

Run Laravel's scheduler every minute:

```cron
* * * * * cd /var/www/jeterp && php artisan schedule:run >/dev/null 2>&1
```

Recommended server schedules are hourly `erp:health` and a monthly restore drill against an isolated database. For the production-to-Google-Drive-to-local-DR workflow use the reviewed scripts in `scripts/backup/`, not Laravel's basic local `erp:backup` command.

## Backup and restore

`php artisan erp:backup` creates a compressed SQL backup and SHA-256 checksum for an on-demand local safeguard. The complete nightly DR flow is documented in [BACKUP-DR-RUNBOOK.md](BACKUP-DR-RUNBOOK.md): PostgreSQL custom archive plus uploaded files, SHA-256 validation, encrypted `rclone crypt` Google Drive copy, and a local restore limited to `jeterp_dr`.

`php artisan erp:restore-drill` verifies the latest local backup. Set `ERP_RESTORE_DATABASE` to a disposable database and add `--execute` to perform a full restore test. It refuses to restore over the configured application database.

Do not install the backup cron examples until the manual Google Drive round-trip
and local `jeterp_dr` restore drill have passed and are recorded in
`docs/ai/backup-dr-uat.md`.

## Deployment

Run the `Deploy ERP` GitHub workflow and choose staging or production. The job tests the repository first, connects with a deploy-only SSH key, fast-forwards the selected branch, installs production dependencies, migrates, caches Laravel, and runs health checks.

## Daily checks

`php artisan erp:health` returns a non-zero exit code when the database, migrations, writable storage, or backup freshness check fails. Queue failures are warnings. Open incidents are recorded in Monitoring and newly detected incidents can be pushed through LINE Messaging API.

## Lot quality workflow

1. Receive goods with lot number, manufacture date, and expiry date.
2. Hold or quarantine questionable lots. Normal sales and production stop allocating them.
3. Record QC results and evidence in Lot Trace.
4. Transfers preserve the source lot. Transform batches store every input-to-output lot lineage.
5. Opening a recall marks downstream lots and creates follow-up rows for affected sales documents and customers.
6. Release accepted lots or clear rejected stock with a damage document. Quality changes remain auditable.

## Purchase and POS controls

- PO requesters cannot approve their own requests.
- Stock adjustments remain pending and do not affect stock until a different authorized user approves them.
- POS recalculates prices, promotions, discounts, points, VAT, and quantity promotions on the server.
- Manual discount and below-cost sale exceptions require manager permissions.

## nginx: gzip และ cache ไฟล์ static

> **คำเตือน: เปิด `expires 30d` ได้หลัง deploy งานข้อ 3.2 (ใส่ `?v=` บนไฟล์ CSS/JS ผ่าน `AssetVersion::url`) แล้วเท่านั้น มิฉะนั้นผู้ใช้จะค้างอยู่กับไฟล์ CSS/JS เวอร์ชันเก่าที่เบราว์เซอร์แคชไว้**

เพิ่มค่าต่อไปนี้ใน nginx (ระดับ `http` หรือ `server` ตามโครงสร้างไฟล์บนเซิร์ฟเวอร์) เพื่อซ่อนเวอร์ชัน nginx, บีบอัดไฟล์ static และให้เบราว์เซอร์แคชไฟล์ที่มี `?v=`:

```nginx
server_tokens off;

gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types text/css application/javascript application/json image/svg+xml;
```

และเพิ่ม `location` block ต่อไปนี้ใน `server` block ของ ERP:

```nginx
location ~* \.(css|js|woff2?|svg|png|jpg|jpeg|ico)$ {
    expires 30d;
    add_header Cache-Control "public";
    try_files $uri =404;
}
```

หลังแก้ไขคอนฟิก ให้ตรวจไวยากรณ์และโหลด nginx ใหม่ด้วยคำสั่ง:

```bash
nginx -t && systemctl reload nginx
```
