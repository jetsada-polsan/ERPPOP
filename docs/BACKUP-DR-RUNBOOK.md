# POPSTAR ERP Backup and Local DR Runbook

## Purpose

This is a nightly backup and warm-standby design, not real-time replication.
Production stays on `27.254.143.219`; the local server restores only to
`jeterp_dr`. Initial target RPO is 24 hours.

## Safety boundaries

- Never restore into production database `jeterp`.
- Never point `DR_STORAGE_PATH` to production storage.
- Never use `rclone sync`, `rsync --delete`, or a cloud folder that is not an
  `rclone crypt` remote.
- Do not put secrets, rclone configuration, backup archives, or database dumps
  in this repository.
- Cron is disabled until the manual drill described below passes.

## Install prerequisites

On both Linux servers install the PostgreSQL client tools, `rclone`, GNU tar
with zstd support, and `flock`. The production host additionally needs Git.

Create root-only configuration directories:

```bash
install -d -m 700 /etc/jeterp-backup /var/log/jeterp-backup
install -m 600 scripts/backup/production.env.example /etc/jeterp-backup/production.env
install -m 600 scripts/backup/local-dr.env.example /etc/jeterp-backup/local-dr.env
```

Fill values in the appropriate file locally. Do not copy `.env` into either
file or into chat. Configure `rclone` interactively as root using the config
path in the relevant env file, then create a `crypt` remote backed by a Google
Drive remote. Keep the crypt passwords in the company password manager and an
offline recovery record.

Install the scripts together outside the web root so their relative verifier
path remains valid:

```bash
install -d -m 750 /opt/jeterp-backup/bin
install -m 750 scripts/backup/verify-backup.sh /opt/jeterp-backup/bin/verify-backup.sh
install -m 750 scripts/backup/production-backup.sh /opt/jeterp-backup/bin/production-backup.sh
install -m 750 scripts/backup/local-dr-pull-restore.sh /opt/jeterp-backup/bin/local-dr-pull-restore.sh
ln -sf /opt/jeterp-backup/bin/production-backup.sh /usr/local/sbin/jeterp-production-backup
ln -sf /opt/jeterp-backup/bin/local-dr-pull-restore.sh /usr/local/sbin/jeterp-local-dr-restore
```

## Manual drill, before cron

1. On production run `production-backup.sh`. It creates a dated folder under
   `BACKUP_ROOT/daily`, verifies custom PostgreSQL archive and storage archive,
   then uploads it through the encrypted remote.
2. Confirm Google Drive shows encrypted names, not `database.dump` or readable
   company filenames.
3. On the local server run `local-dr-pull-restore.sh YYYY-MM-DD` with that date.
4. Confirm the command reports checksum verification and restores only to
   `jeterp_dr`.
5. Inspect `restore-*.json` in the pulled daily folder; it includes non-secret
   products/users row counts and restore timestamp.
6. In a test copy, alter one byte in `database.dump`. `verify-backup.sh` must
   fail and `local-dr-pull-restore.sh` must not restore it.
7. Simulate rclone upload failure. Existing successful folders must remain.

Record backup date, SHA check, Drive check, restore result, elapsed RTO, and
the test operator in `docs/ai/backup-dr-uat.md` without secrets.

## Cron after approval

Only after the full manual drill passes and the owner approves, install the
examples from `scripts/backup/install-cron.example`. Verify both host timezones
are `Asia/Bangkok`. The production job runs at 00:05 and the local pull/restore
at 01:00. Logs must remain root-readable only.

## Recovery decision

For a production outage, do not point users at `jeterp_dr` automatically.
First confirm the newest DR restore timestamp, database counts, storage files,
and application configuration. Promote the local DR copy only under an
incident decision; it must have notifications, queues, cache namespaces, and
external integrations isolated from production.
