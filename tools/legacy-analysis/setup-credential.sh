#!/usr/bin/env bash
# เก็บรหัสผ่าน SQL Server ระบบเดิมเข้า macOS Keychain แล้วทดสอบการเชื่อมต่อทันที
#
# รันครั้งเดียวจบ รหัสผ่านไม่ถูกแสดงบนจอ ไม่ลง shell history ไม่ลงไฟล์ ไม่ขึ้น GitHub
#
#   bash tools/legacy-analysis/setup-credential.sh
set -euo pipefail

ACCOUNT="jetsada"
SERVICE="erppop-legacy-mssql"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

if security find-generic-password -a "$ACCOUNT" -s "$SERVICE" >/dev/null 2>&1; then
    echo "มีรหัสผ่านของ $ACCOUNT อยู่ใน Keychain แล้ว"
    read -r -p "จะเขียนทับด้วยรหัสใหม่ไหม? [y/N] " reply
    if [[ ! "$reply" =~ ^[Yy]$ ]]; then
        echo "ใช้รหัสเดิมต่อ"
    else
        security delete-generic-password -a "$ACCOUNT" -s "$SERVICE" >/dev/null
        security add-generic-password -a "$ACCOUNT" -s "$SERVICE" -w
    fi
else
    echo "ใส่รหัสผ่านของ SQL login \"$ACCOUNT\" (พิมพ์แล้วจะไม่ขึ้นบนจอ):"
    security add-generic-password -a "$ACCOUNT" -s "$SERVICE" -w
fi

echo
echo "ทดสอบการเชื่อมต่อ (READ COMMITTED, SELECT อย่างเดียว)..."
php "$ROOT/tools/legacy-analysis/mssql_readonly.php" \
    "SELECT @@SERVERNAME AS server_name, DB_NAME() AS database_name, SUSER_SNAME() AS login_name"

echo
echo "ต่อได้แล้ว — บอก Claude ว่า \"ใส่แล้ว\" ได้เลย"
