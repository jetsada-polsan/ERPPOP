#!/usr/bin/env bash
set -euo pipefail

# อัปโหลดตัวติดตั้ง Python POS ขึ้นเครื่องจริง ให้หน้า ตั้งค่า → ดาวน์โหลด POS หยิบไปเสิร์ฟ
#
# เคยทำด้วยมือมาก่อนแล้วเกือบพลาด สคริปต์นี้เลยบังคับสามอย่าง
#   1. เทียบ sha256 สองฝั่ง ไฟล์ 170 MB ขาดกลางทางแล้วดูไม่ออกด้วยตา
#   2. อัปเข้าโฟลเดอร์ชั่วคราวก่อนแล้วค่อย mv ทับ คนที่กำลังโหลดอยู่จะไม่ได้ไฟล์ครึ่งใบ
#   3. ดันรุ่นเดิมไปเป็น .previous.exe ซึ่งอยู่นอก glob ที่ ERP มองหา
#      เหลือรุ่นเดียวที่เสิร์ฟจริงเสมอ แต่ย้อนกลับได้ด้วยการเปลี่ยนชื่อคืน
#
# ใช้: scripts/publish-pos-python.sh 0.2.0 /path/to/POPSTAR-Python-POS-UAT-0.2.0-setup.exe

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ -f deploy/env.local ]]; then
  # shellcheck disable=SC1091
  source deploy/env.local
fi

: "${SSH_USER:=root}"
: "${SSH_HOST:=27.254.143.219}"
: "${SSH_PORT:=22}"
: "${SSH_IDENTITY_FILE:=${HOME}/.ssh/id_ed25519_erppop}"
: "${REMOTE_PATH:=/var/www/jeterp}"
: "${BASE_URL:=http://27.254.143.219}"

VERSION="${1:-}"
INSTALLER="${2:-}"

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "ต้องระบุเวอร์ชันแบบ 1.2.3 — ใช้: $0 <version> <setup.exe>" >&2
  exit 1
fi

if [[ ! -f "$INSTALLER" ]]; then
  echo "ไม่พบไฟล์ติดตั้ง: $INSTALLER" >&2
  exit 1
fi

# ชื่อไฟล์ต้องตรง glob ที่ SystemSettingController มองหา ไม่งั้นอัปขึ้นไปแล้วเงียบ
REMOTE_NAME="POPSTAR-Python-POS-UAT-${VERSION}-setup.exe"
RELEASE_DIR="${REMOTE_PATH}/storage/app/pos-python-releases"
STAGING="${RELEASE_DIR}/.incoming-$$"

SSH_OPTIONS=(-p "$SSH_PORT")
SCP_OPTIONS=(-P "$SSH_PORT")
if [[ -f "$SSH_IDENTITY_FILE" ]]; then
  SSH_OPTIONS+=(-i "$SSH_IDENTITY_FILE" -o IdentitiesOnly=yes)
  SCP_OPTIONS+=(-i "$SSH_IDENTITY_FILE" -o IdentitiesOnly=yes)
fi

if [[ "$(head -c 2 "$INSTALLER")" != "MZ" ]]; then
  echo "ไฟล์นี้ไม่ใช่โปรแกรม Windows (ไม่ขึ้นต้นด้วย MZ)" >&2
  exit 1
fi

LOCAL_SUM="$(shasum -a 256 "$INSTALLER" | cut -d' ' -f1)"
LOCAL_MB="$(( $(wc -c < "$INSTALLER") / 1048576 ))"
echo "กำลังส่ง ${REMOTE_NAME} (${LOCAL_MB} MB, sha256 ${LOCAL_SUM:0:12}…) ไป ${SSH_USER}@${SSH_HOST}"

ssh "${SSH_OPTIONS[@]}" "${SSH_USER}@${SSH_HOST}" "mkdir -p '${STAGING}'"
scp "${SCP_OPTIONS[@]}" "$INSTALLER" "${SSH_USER}@${SSH_HOST}:${STAGING}/${REMOTE_NAME}"

ssh "${SSH_OPTIONS[@]}" "${SSH_USER}@${SSH_HOST}" bash -s -- \
  "$RELEASE_DIR" "$STAGING" "$REMOTE_NAME" "$LOCAL_SUM" <<'REMOTE'
set -euo pipefail
release_dir="$1"; staging="$2"; name="$3"; expected="$4"
trap 'rm -rf "$staging"' EXIT

actual="$(sha256sum "${staging}/${name}" | cut -d' ' -f1)"
if [[ "$actual" != "$expected" ]]; then
  echo "sha256 ไม่ตรงกัน ไฟล์เสียระหว่างส่ง — ไม่ทับของเดิม" >&2
  exit 1
fi

# ดันรุ่นที่เสิร์ฟอยู่ออกจาก glob ก่อน จะได้เหลือรุ่นเดียวที่ ERP มองเห็น
shopt -s nullglob
for old in "${release_dir}"/POPSTAR-Python-POS-UAT-*-setup.exe; do
  [[ "$(basename "$old")" == "$name" ]] && continue
  mv -f "$old" "${old%.exe}.previous.exe"
  echo "  เก็บรุ่นเดิมไว้เป็น $(basename "${old%.exe}.previous.exe")"
done

mv -f "${staging}/${name}" "${release_dir}/${name}"
chown www-data:www-data "${release_dir}/${name}"
chmod 664 "${release_dir}/${name}"
echo "  วางไฟล์แล้ว: $(ls -la "${release_dir}/${name}" | awk '{print $5" ไบต์  "$9}')"
REMOTE

echo "ตรวจปลายทาง:"
code="$(curl -s -o /dev/null -w '%{http_code}' -I "${BASE_URL}/download/python-pos" || true)"
echo "  GET /download/python-pos → ${code}"
[[ "$code" == "200" || "$code" == "302" ]] || { echo "หน้าดาวน์โหลดยังไม่เสิร์ฟไฟล์" >&2; exit 1; }
echo "เสร็จ — ดาวน์โหลดได้จาก ตั้งค่า → ดาวน์โหลด POS"
