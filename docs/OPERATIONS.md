# POPSTAR ERP Operations

## Environments

- Production uses its own database, `.env`, storage and domain.
- Staging must use a separate database, storage path and LINE test target. Never point staging at production data.
- Create GitHub environments named `staging` and `production`. Add `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_SSH_KEY` secrets and an `APP_DIR` variable to each environment.
- Production deployment should require an environment reviewer in GitHub.

## Scheduled jobs

Run Laravel's scheduler every minute:

```cron
* * * * * cd /var/www/jeterp && php artisan schedule:run >/dev/null 2>&1
```

Recommended server schedules are daily `erp:backup --disk=<offsite disk>`, hourly `erp:health`, and a monthly restore drill against an isolated database.

## Backup and restore

`php artisan erp:backup` creates a compressed SQL backup and SHA-256 checksum. Set `ERP_BACKUP_OFFSITE_DISK` to copy both files to a configured remote filesystem.

`php artisan erp:restore-drill` verifies the latest local backup. Set `ERP_RESTORE_DATABASE` to a disposable database and add `--execute` to perform a full restore test. It refuses to restore over the configured application database.

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
