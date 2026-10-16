#!/usr/bin/env bash
# อ่านอย่างเดียว: ดูว่า server มีอะไรพร้อมสำหรับ backup อัตโนมัติแล้วบ้าง (ไม่แสดงค่าลับ)
APP_DIR="${APP_DIR:-/var/www/jeterp}"
cd "$APP_DIR" || exit 1
r() { echo "RESULT $1=$2"; }
r cron_lines "$( (crontab -l 2>/dev/null; cat /etc/cron.d/* 2>/dev/null) | grep -E 'jeterp|artisan|backup' | grep -v '^#' | sed 's/[[:space:]]\+/ /g' | tr '\n' ';' | cut -c1-400)"
r rclone "$(command -v rclone >/dev/null && rclone version 2>/dev/null | head -1 || echo missing)"
r rclone_remotes "$(rclone listremotes 2>/dev/null | tr '\n' ' ' || true)"
r pg_dump "$(command -v pg_dump >/dev/null && pg_dump --version || echo missing)"
r backup_env_file "$( [[ -f scripts/backup/production.env ]] && echo present || echo missing)"
r backups_local "$(ls -1 storage/app/backups 2>/dev/null | grep -vc sha256) files, newest $(ls -t storage/app/backups 2>/dev/null | grep -v sha256 | head -1)"
r disk_free "$(df -h "$APP_DIR" | awk 'NR==2{print $4" free of "$2}')"
r scheduler "$(php artisan schedule:list 2>/dev/null | grep -iE 'backup|health' | sed 's/[[:space:]]\+/ /g' | tr '\n' ';' | cut -c1-300)"
