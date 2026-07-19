# POPSTAR ERP Operations

## Daily checks

Run:

```bash
php artisan erp:health
```

The command returns a non-zero exit code when the database, migrations, writable
storage, or backup freshness check fails. Queue failures are reported as a
warning so they can be investigated without marking the entire site offline.

## Database backup

Create a compressed local backup and SHA-256 checksum:

```bash
php artisan erp:backup --keep-days=30
```

Files are written with mode `0600` to `storage/app/backups`. The command
supports PostgreSQL, MySQL, and SQLite. A production schedule should run this
before business hours and copy the resulting `.gz` and `.sha256` files to
encrypted storage outside the ERP server.

Example cron:

```cron
15 2 * * * cd /var/www/jeterp && /usr/bin/php artisan erp:backup --keep-days=30 >> storage/logs/backup.log 2>&1
45 2 * * * cd /var/www/jeterp && /usr/bin/php artisan erp:health --max-backup-age=26 >> storage/logs/health.log 2>&1
```

Do not consider a backup complete until a restore drill has been performed on a
separate database. Perform and record a restore drill at least quarterly.

## Lot quality workflow

1. Receive goods with lot number, manufacture date, and expiry date. If product
   shelf life is configured, expiry is calculated from manufacture date.
2. Mark a questionable lot as Hold or Quarantine from the product page. Normal
   sales, production, and requisitions stop allocating that lot immediately.
3. Use Trace to list the receiving document and every downstream stock movement.
4. Mark a confirmed unsafe lot as Recalled. Use the traced documents to identify
   affected branches and transactions.
5. Release an accepted lot back to Available, or clear rejected stock with a
   damage document. Every quality status change is written to the audit log.

## Purchase and POS controls

- The PO requester cannot approve their own request. Approval requires the
  `purchasing.approve` permission.
- POS recalculates product prices, promotions, discount cards, points, VAT, and
  quantity promotions on the server. Client totals that differ are rejected.
- Manual discount and below-cost sale exceptions require their dedicated
  manager permissions.
