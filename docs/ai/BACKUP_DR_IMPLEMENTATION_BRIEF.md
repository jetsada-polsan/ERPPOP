# POPSTAR ERP - Nightly Backup and Local Disaster Recovery

## Objective

Implement a nightly backup and warm-standby recovery design for POPSTAR ERP:

1. Production ERP remains the live system on `27.254.143.219` (`/var/www/jeterp`).
2. A complete, encrypted backup is uploaded to Google Drive every night.
3. A local company server pulls the encrypted backup from Google Drive and restores it into an isolated DR database.
4. No job is allowed to overwrite the production database, the local operational database, or an existing known-good backup.

This is **backup + warm standby**, not real-time database replication. Initial RPO is 24 hours.

## Required Schedule (Asia/Bangkok)

| Time | Machine | Action |
| --- | --- | --- |
| 00:05 | Production host | Create PostgreSQL custom dump, storage archive, globals dump, manifest and checksums |
| 00:20 | Production host | Verify archive and checksums locally |
| 00:30 | Production host | Upload encrypted immutable daily folder to Google Drive |
| 01:00 | Local server | Pull the new daily folder from Google Drive and verify checksums |
| 01:20 | Local server | Restore only into `jeterp_dr` and run health checks |
| 01:45 | Local server | Write success/failure log and send notification |

## Non-negotiable Safety Rules

- Never use `rsync --delete` or `rclone sync` for backup uploads.
- Upload with `rclone copy` into a date-stamped directory; do not overwrite a prior daily backup.
- Encrypt cloud data using `rclone crypt`, including file names and file contents.
- Never commit rclone config, OAuth token, encryption password, database password, SSH key, `.env`, or backup data.
- All secrets live outside the repository in root-owned files with mode `0600`.
- Use `flock` so two backup jobs cannot overlap.
- A failed backup must retain all prior successful backups and exit non-zero.
- Restore only into a dedicated local DR database named `jeterp_dr`; never restore automatically into production or a local live ERP database.
- The local DR server must pull from Google Drive. Do not expose an inbound port in the company network for the production host.

## Artifacts in Every Daily Backup

Create one directory such as `2026-08-23/` containing:

```text
database.dump                 PostgreSQL pg_dump custom archive (-Fc)
globals.sql                   pg_dumpall --globals-only (encrypted by rclone crypt)
storage.tar.zst               Laravel user uploads/documents/slips only
manifest.json                 time, host, database, app git commit, Laravel version
SHA256SUMS                    SHA-256 for every artifact
SUCCESS                       written only after all checks pass
```

Do not archive `vendor`, cache, logs, temporary files, source `.git`, or raw `.env`.
Code is recovered from GitHub at the recorded commit; user-uploaded `storage` data is recovered from `storage.tar.zst`.

## Retention

- Production local disk: 14 daily backups
- Google Drive encrypted backup: 90 daily backups, 12 monthly backups
- Local DR disk: 30 daily backups
- Retention deletion must only affect completed folders containing both `SHA256SUMS` and `SUCCESS`.
- Implement retention only after initial backup and restore are proven. Record deleted folder names in the log.

## Required Configuration (Do Not Invent Values)

Create root-owned configuration files outside Git:

```text
/etc/jeterp-backup/production.env
/etc/jeterp-backup/local-dr.env
```

Required values include:

```bash
PGHOST=...
PGPORT=5432
PGDATABASE=...
PGUSER=...
PGPASSWORD=...
BACKUP_ROOT=/var/backups/jeterp
RCLONE_CONFIG=/etc/jeterp-backup/rclone.conf
RCLONE_CRYPT_REMOTE=gdrive-crypt:POPSTAR-ERP
APP_PATH=/var/www/jeterp
STORAGE_PATH=/var/www/jeterp/storage/app
DR_DATABASE=jeterp_dr
```

Claude must stop and request the owner to fill unknown values. Do not read or copy production `.env` into Git or chat.

## Implementation Deliverables

Create, test, and document these files:

```text
scripts/backup/production-backup.sh
scripts/backup/local-dr-pull-restore.sh
scripts/backup/verify-backup.sh
scripts/backup/install-cron.example
docs/OPERATIONS.md             update with install, restore, rollback and emergency steps
docs/BACKUP-DR-RUNBOOK.md      operator instructions and restore drill checklist
```

Scripts must use `set -Eeuo pipefail`, `umask 077`, absolute command paths where practical, `flock`, explicit error traps, structured logs, and non-zero exit status on failure.

## Backup Commands and Validation

Use PostgreSQL custom format for flexible, portable restore:

```bash
pg_dump --format=custom --file database.dump "$PGDATABASE"
pg_dumpall --globals-only > globals.sql
pg_restore --list database.dump > /dev/null
sha256sum database.dump globals.sql storage.tar.zst > SHA256SUMS
sha256sum --check SHA256SUMS
```

`pg_dump` custom format is designed for `pg_restore`, including selective restore and archive validation. [PostgreSQL pg_dump](https://www.postgresql.org/docs/current/app-pgdump.html)

## Google Drive

1. Configure an rclone Google Drive remote interactively as the server owner.
2. Configure a separate `crypt` remote backed by that Drive folder.
3. Store rclone configuration at `/etc/jeterp-backup/rclone.conf`, mode `0600`.
4. Upload only after local validation:

```bash
rclone copy "$DAILY_DIR" "$RCLONE_CRYPT_REMOTE/$DATE" --checksum --immutable
```

5. Verify transfer with `rclone check` or local checksum after pull.

Do not use Google Drive as an unencrypted filesystem. Loss of rclone crypt passwords makes the cloud backup unrecoverable; store recovery material in the company password manager and an offline sealed copy.

## Local DR Restore

The local machine must:

1. Pull only the completed daily folder containing `SUCCESS`.
2. Verify SHA-256 before restore.
3. Confirm the backup date is newer than its last successful restore.
4. Disconnect users from **only** `jeterp_dr`.
5. Recreate/restore **only** `jeterp_dr` from `database.dump`.
6. Restore storage into a DR-only storage path.
7. Run SQL and Laravel health checks against the DR environment.
8. Record restore timestamp, backup hash, database row count samples and result.

The local DR application's `.env` must point only to `jeterp_dr`, have outbound notifications disabled unless explicitly configured, and never share queues, cache keys, or storage with production.

## Cron

Provide examples only; do not install cron until the owner approves after manual test:

```cron
# Production server - 00:05 Bangkok time
5 0 * * * /usr/local/sbin/jeterp-production-backup

# Local DR server - 01:00 Bangkok time
0 1 * * * /usr/local/sbin/jeterp-local-dr-restore
```

Verify server timezone before installation. Cron log output must go to a protected log file and be rotated.

## Acceptance Tests Before Enabling Nightly Jobs

1. Manual production-style backup finishes successfully without affecting ERP.
2. SHA-256 validates before upload and after local pull.
3. Google Drive contains encrypted names/content only.
4. A fresh `jeterp_dr` restores successfully from the uploaded backup.
5. DR health checks run against restored data.
6. Deliberately corrupting a test copy causes checksum verification to fail and prevents restore.
7. Simulate rclone failure; prior backups remain intact and alert/log is generated.
8. Confirm no production DB, production storage, or existing successful backup is overwritten.

## Completion Report

Write `docs/ai/backup-dr-uat.md` containing configuration paths (never secrets), exact schedules, backup IDs, SHA validation result, restore result, retention policy, RPO/RTO, and unresolved risks.

Do not deploy or install the cron jobs until the owner explicitly approves the manual backup-and-restore test results.
