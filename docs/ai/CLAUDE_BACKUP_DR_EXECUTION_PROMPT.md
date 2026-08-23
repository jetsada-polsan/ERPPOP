# Claude Handoff: POPSTAR ERP Backup and Local DR

Read this file together with `docs/ai/BACKUP_DR_IMPLEMENTATION_BRIEF.md` before changing anything.

## Objective

Implement a tested backup and warm-standby DR workflow for POPSTAR ERP. Production stays on `27.254.143.219`; the local company server receives a restored copy for disaster recovery. This is not real-time replication.

## Required Schedule (Asia/Bangkok)

```text
Production host
00:05  Create PostgreSQL backup plus Laravel uploaded files/slips.
       Validate SHA-256 and confirm the database archive opens.
00:30  Upload the validated backup to Google Drive using encrypted rclone crypt.

Local company server
01:00  Pull the latest completed encrypted backup from Google Drive.
       Validate SHA-256 again.
       Restore only into a separate local database named jeterp_dr.
       Run health checks and record the result.
```

## Hard Safety Rules

1. Never write to, restore over, migrate destructively, or reset the production database as part of this work.
2. Never restore over the local live ERP database. Restore only to `jeterp_dr`.
3. Never commit credentials, `.env`, rclone configuration, OAuth token, encryption password, SSH key, backup archive, or real customer data.
4. Do not use `rclone sync` or `rsync --delete` for backups. Use immutable date-stamped folders and `rclone copy`.
5. Encrypt Google Drive data and names with `rclone crypt`.
6. Do not install cron until a manual backup, cloud transfer, local pull, checksum validation, and DR restore have all passed and the owner approves.
7. Unknown server IPs, paths, database credentials, rclone OAuth setup, and retention decisions must be requested from the owner; do not invent values.

## Deliverables

Create and test:

```text
scripts/backup/production-backup.sh
scripts/backup/local-dr-pull-restore.sh
scripts/backup/verify-backup.sh
scripts/backup/install-cron.example
docs/BACKUP-DR-RUNBOOK.md
docs/OPERATIONS.md                 # update relevant operator steps
docs/ai/backup-dr-uat.md           # test evidence, no secrets
```

The scripts must use `set -Eeuo pipefail`, `umask 077`, `flock`, protected logs, error traps, SHA-256 verification, and non-zero failure status. Use a PostgreSQL custom archive (`pg_dump -Fc`) validated by `pg_restore --list`.

## Required Evidence Before Requesting Deployment Approval

Report all of the following:

- exact non-secret configuration paths and permissions;
- a successful manual backup ID and SHA-256 result;
- proof that the Google Drive copy is encrypted;
- a successful local pull and checksum result;
- a restore into `jeterp_dr`, including post-restore application DB permissions;
- DR health-check output and a small row-count comparison;
- failed-checksum and failed-upload tests proving restore is blocked safely;
- stated RPO (24 hours initially), measured RTO, retention policy, and unresolved risks.

Do not claim the workflow is complete until the restore drill succeeds using a backup that has travelled through Google Drive.
