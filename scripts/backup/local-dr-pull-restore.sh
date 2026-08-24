#!/usr/bin/env bash
# Local company server: pull a completed crypt backup and restore ONLY DR_DATABASE.
set -Eeuo pipefail
umask 077

CONFIG_FILE=${JETERP_DR_CONFIG:-/etc/jeterp-backup/local-dr.env}
[[ -r "$CONFIG_FILE" ]] || { printf 'ERROR: missing %s\n' "$CONFIG_FILE" >&2; exit 1; }
# shellcheck disable=SC1090
source "$CONFIG_FILE"

log() { printf '%s [local-dr] %s\n' "$(date -Is)" "$*"; }
die() { log "ERROR: $*" >&2; exit 1; }
trap 'status=$?; log "FAILED status=${status}" >&2; exit "$status"' ERR

: "${PGHOST:?}" "${PGPORT:?}" "${PGUSER:?}" "${PGPASSWORD:?}"
: "${DR_DATABASE:?}" "${DR_BACKUP_ROOT:?}" "${DR_STORAGE_PATH:?}"
: "${RCLONE_CONFIG:?}" "${RCLONE_CRYPT_REMOTE:?}"
APP_DB_USER=${APP_DB_USER:-$PGUSER}
TZ=${TZ:-Asia/Bangkok}
export TZ PGPASSWORD RCLONE_CONFIG

for tool in flock rclone sha256sum pg_restore psql dropdb createdb tar; do command -v "$tool" >/dev/null || die "required command missing: $tool"; done
[[ "$DR_DATABASE" != "${PGDATABASE:-}" ]] || die 'DR_DATABASE must not equal PGDATABASE'
[[ "$DR_DATABASE" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || die 'DR_DATABASE contains invalid characters'
[[ "$DR_STORAGE_PATH" != /var/www/jeterp/storage* ]] || die 'DR_STORAGE_PATH must be a dedicated local DR path, never production storage'

mkdir -p "$DR_BACKUP_ROOT/daily" "$DR_BACKUP_ROOT/.incoming" "$DR_BACKUP_ROOT/storage-previous"
chmod 0700 "$DR_BACKUP_ROOT" "$DR_BACKUP_ROOT/daily" "$DR_BACKUP_ROOT/.incoming" "$DR_BACKUP_ROOT/storage-previous"
exec 9>"$DR_BACKUP_ROOT/.local-dr.lock"
flock -n 9 || die 'another local DR job is already running'

requested_date=${1:-}
if [[ -n "$requested_date" ]]; then
  backup_date=$requested_date
else
  backup_date=$(rclone lsf "${RCLONE_CRYPT_REMOTE%/}/" --dirs-only | sed 's:/$::' | grep -E '^20[0-9]{2}-[0-9]{2}-[0-9]{2}$' | sort | tail -n 1)
fi
[[ -n "$backup_date" ]] || die 'no completed backup folder found on Google Drive'
remote_dir="${RCLONE_CRYPT_REMOTE%/}/$backup_date"
final_dir="$DR_BACKUP_ROOT/daily/$backup_date"

if [[ -d "$final_dir" ]]; then
  "$(dirname "$0")/verify-backup.sh" "$final_dir"
  log "backup already pulled and verified: $backup_date"
else
  incoming=$(mktemp -d "$DR_BACKUP_ROOT/.incoming/${backup_date}.XXXXXX")
  trap 'rm -rf "${incoming:-}"' EXIT
  log "pulling encrypted backup $backup_date"
  rclone copy "$remote_dir" "$incoming" --checksum
  "$(dirname "$0")/verify-backup.sh" "$incoming"
  rclone check "$incoming" "$remote_dir" --checksum
  mv "$incoming" "$final_dir"
  incoming=''
fi

# The restore target is constrained to the dedicated DR database. Never point this
# at a live application database. --no-owner/--no-privileges matches production dump.
log "restoring $backup_date into database $DR_DATABASE"
dropdb --if-exists --force --host "$PGHOST" --port "$PGPORT" --username "$PGUSER" "$DR_DATABASE"
createdb --host "$PGHOST" --port "$PGPORT" --username "$PGUSER" "$DR_DATABASE"
pg_restore --no-owner --no-privileges --host "$PGHOST" --port "$PGPORT" --username "$PGUSER" --dbname "$DR_DATABASE" "$final_dir/database.dump"

quoted_app_user=$(printf '%s' "$APP_DB_USER" | sed 's/"/""/g')
psql --host "$PGHOST" --port "$PGPORT" --username "$PGUSER" --dbname "$DR_DATABASE" -v ON_ERROR_STOP=1 <<SQL
GRANT USAGE ON SCHEMA public TO "$quoted_app_user";
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO "$quoted_app_user";
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO "$quoted_app_user";
SQL

restore_stamp=$(date +%Y%m%d-%H%M%S)
incoming_storage="${DR_STORAGE_PATH}.incoming-${restore_stamp}"
rm -rf "$incoming_storage"
mkdir -p "$incoming_storage"
tar --extract --zstd --file "$final_dir/storage.tar.zst" --directory "$incoming_storage"
if [[ -e "$DR_STORAGE_PATH" ]]; then
  mv "$DR_STORAGE_PATH" "$DR_BACKUP_ROOT/storage-previous/storage-${restore_stamp}"
fi
mv "$incoming_storage" "$DR_STORAGE_PATH"

health_file="$final_dir/restore-${restore_stamp}.json"
count_products=$(psql --tuples-only --no-align --host "$PGHOST" --port "$PGPORT" --username "$PGUSER" --dbname "$DR_DATABASE" -c 'SELECT count(*) FROM products;')
count_users=$(psql --tuples-only --no-align --host "$PGHOST" --port "$PGPORT" --username "$PGUSER" --dbname "$DR_DATABASE" -c 'SELECT count(*) FROM users;')
printf '{"backup_date":"%s","restored_at":"%s","database":"%s","products":%s,"users":%s,"status":"success"}\n' \
  "$backup_date" "$(date -Is)" "$DR_DATABASE" "$count_products" "$count_users" > "$health_file"
chmod 0600 "$health_file"
log "SUCCESS backup_date=$backup_date database=$DR_DATABASE products=$count_products users=$count_users"
