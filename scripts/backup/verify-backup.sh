#!/usr/bin/env bash
# Verify a single completed POPSTAR ERP backup directory. No database writes.
set -Eeuo pipefail
umask 077

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
backup_dir=${1:?"usage: verify-backup.sh /path/to/YYYY-MM-DD"}

[[ -d "$backup_dir" ]] || die "backup directory not found: $backup_dir"
[[ -f "$backup_dir/SUCCESS" ]] || die "backup is incomplete (SUCCESS marker missing)"
[[ -f "$backup_dir/SHA256SUMS" ]] || die "SHA256SUMS missing"
[[ -s "$backup_dir/database.dump" ]] || die "database.dump missing or empty"
[[ -s "$backup_dir/globals.sql" ]] || die "globals.sql missing or empty"
[[ -s "$backup_dir/storage.tar.zst" ]] || die "storage.tar.zst missing or empty"
[[ -s "$backup_dir/manifest.json" ]] || die "manifest.json missing or empty"

command -v sha256sum >/dev/null || die 'sha256sum is required'
command -v pg_restore >/dev/null || die 'pg_restore is required'
command -v tar >/dev/null || die 'tar is required'

(cd "$backup_dir" && sha256sum --check --strict SHA256SUMS)
pg_restore --list "$backup_dir/database.dump" >/dev/null
tar --list --zstd --file "$backup_dir/storage.tar.zst" >/dev/null
printf 'Verified backup: %s\n' "$backup_dir"
