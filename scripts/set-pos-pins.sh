#!/bin/bash
# ตั้ง PIN เริ่มต้นให้รหัสคนขาย POS บน production ทีละรหัส
# ใช้: bash scripts/set-pos-pins.sh B001-2 B001-3
# PIN ถามตอนรัน ไม่แสดงบนจอ ไม่ลง history ไม่เขียนลงไฟล์ — เครื่อง POS บังคับให้เปลี่ยนตอนล็อกอินครั้งแรก
set -euo pipefail
[[ $# -gt 0 ]] || { echo "ระบุรหัสคนขาย เช่น: bash scripts/set-pos-pins.sh B001-2 B001-3"; exit 1; }

used=()
for code in "$@"; do
  while true; do
    read -r -s -p "PIN ${code} (ตัวเลข 6 หลัก): " pin; echo >&2
    read -r -s -p "พิมพ์ PIN ${code} อีกครั้ง: " again; echo >&2
    pin="${pin//[^0-9]/}"; again="${again//[^0-9]/}"
    if [[ ! "$pin" =~ ^[0-9]{6}$ ]]; then echo "  ต้องเป็นตัวเลข 6 หลัก (ถ้าแป้นพิมพ์เป็นภาษาไทย ให้สลับเป็นอังกฤษ)" >&2; continue; fi
    if [[ "$pin" != "$again" ]]; then echo "  สองครั้งไม่ตรงกัน" >&2; continue; fi
    if [[ "$pin" =~ ^(012345|123456|234567|345678|456789|987654|654321)$ || "$pin" =~ ^([0-9])\1{5}$ ]]; then echo "  เดาง่ายเกินไป" >&2; continue; fi
    if [[ " ${used[*]:-} " == *" $pin "* ]]; then echo "  ซ้ำกับรหัสอื่นในรอบนี้ แต่ละเครื่อง/หัวหน้าต้องใช้ PIN ต่างกัน" >&2; continue; fi
    break
  done
  used+=("$pin")
  ssh jeterp "cd /var/www/jeterp && php artisan pos:pin ${code} ${pin}"
done
unset pin again used
echo "เสร็จ — ล็อกอินครั้งแรกที่เครื่อง POS ระบบจะบังคับเปลี่ยน PIN"
