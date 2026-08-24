#!/usr/bin/env bash
# Production side: create and validate a complete daily backup, then upload it
# through an rclone crypt remote. This script never restores or deletes data.
set -Eeuo pipefail
umask 077

CONFIG_FILE=${JETERP_BACKUP_CONFIG:-/etc/jeterp-backup/production.env}
[[ -r "$CONFIG_FILE" ]] || { printf 'ERROR: missing %s\n' "$CONFIG_FILE" >&2; exit 1; }
# shellcheck disable=SC1090
source "$CONFIG_FILE"

log() { printf '%s [production-backup] %s\n' "$(date -Is)" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }
trap 'status=$?; log "FAILED status=${status}" >&2; exit "$status"' ERR

: "${PGHOST:?}" "${PGPORT:?}" "${PGDATABASE:?}" "${PGUSER:?}" "${PGPASSWORD:?}"
: "${BACKUP_ROOT:?}" "${APP_PATH:?}" "${APP_STORAGE_PATH:?}"
: "${RCLONE_CONFIG:?}" "${RCLONE_CRYPT_REMOTE:?}"
KEEP_DAYS=${KEEP_DAYS:-14}
TZ=${TZ:-Asia/Bangkok}
export TZ PGPASSWORD RCLONE_CONFIG

for tool in flock pg_dump pg_dumpall pg_restore sha256sum tar rclone git; do command -v "$tool" >/dev/null || die "required command missing: $tool"; done
[[ -d "$APP_PATH" && -d "$APP_STORAGE_PATH" ]] || die 'APP_PATH or APP_STORAGE_PATH does not exist'
[[ -f "$RCLONE_CONFIG" ]] || die 'RCLONE_CONFIG does not exist'

mkdir -p "$BACKUP_ROOT/daily" "$BACKUP_ROOT/.inprogress"
chmod 0700 "$BACKUP_ROOT" "$BACKUP_ROOT/daily" "$BACKUP_ROOT/.inprogress"
exec 9>"$BACKUP_ROOT/.production-backup.lock"
flock -n 9 || die 'another production backup is already running'

backup_date=$(date +%F)
daily_dir="$BACKUP_ROOT/daily/$backup_date"
[[ ! -e "$daily_dir" ]] || die "completed backup folder already exists: $daily_dir"
work_dir=$(mktemp -d "$BACKUP_ROOT/.inprogress/${backup_date}.XXXXXX")
cleanup() { [[ -n "${work_dir:-}" ]] && rm -rf -- "$work_dir"; }
trap cleanup EXIT

log "creating database archive for ${PGDATABASE}"
pg_dump --format=custom --no-owner --no-privileges --host "$PGHOST" --port "$PGPORT" --username "$PGUSER" --file "$work_dir/database.dump" "$PGDATABASE"
pg_dumpall --globals-only --host "$PGHOST" --port "$PGPORT" --username "$PGUSER" > "$work_dir/globals.sql"

# Back up application-uploaded files only. Runtime backups/logs/cache are excluded.
log 'creating storage archive'
tar --zstd --create --file "$work_dir/storage.tar.zst" \
  --directory "$APP_STORAGE_PATH" \
  --exclude='./backups' --exclude='./reports' --exclude='./framework' --exclude='./logs' \
  .

commit=$(git -C "$APP_PATH" rev-parse --verify HEAD 2>/dev/null || printf 'unknown')
host=$(hostname -f 2>/dev/null || hostname)
generated=$(date -Is)
printf '{\n  "backup_date": "%s",\n  "generated_at": "%s",\n  "host": "%s",\n  "database": "%s",\n  "git_commit": "%s",\n  "format": "postgres-custom"\n}\n' \
  "$backup_date" "$generated" "$host" "$PGDATABASE" "$commit" > "$work_dir/manifest.json"

(cd "$work_dir" && sha256sum database.dump globals.sql storage.tar.zst manifest.json > SHA256SUMS)
touch "$work_dir/SUCCESS"
"$(dirname "$0")/verify-backup.sh" "$work_dir"

mv "$work_dir" "$daily_dir"
work_dir=''
log "local backup completed: $daily_dir"

# rclone crypt encrypts both file names and contents. copy/immutable never prunes
# or overwrites a known-good remote backup.
remote_dir="${RCLONE_CRYPT_REMOTE%/}/$backup_date"
log "uploading encrypted backup to $remote_dir"
rclone copy "$daily_dir" "$remote_dir" --checksum --immutable
rclone check "$daily_dir" "$remote_dir" --checksum

# Prune completed local folders only; cloud retention is intentionally a separate
# approved operation after the first restore drill succeeds.
find "$BACKUP_ROOT/daily" -mindepth 1 -maxdepth 1 -type d -name '20??-??-??' -mtime "+$KEEP_DAYS" -exec sh -c '
  for d do [ -f "$d/SUCCESS" ] && [ -f "$d/SHA256SUMS" ] && rm -rf -- "$d"; done
' sh {} +
log "SUCCESS backup_date=$backup_date"
