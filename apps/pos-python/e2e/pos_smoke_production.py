"""ทดสอบขายจริงขึ้น ERP production แทนเครื่อง POS (ใช้โค้ดชุดเดียวกับโปรแกรมหน้าร้าน)

ถาม Device Token และ PIN แบบซ่อนตัวอักษร ไม่บันทึกลงไฟล์ ฐานข้อมูลเครื่องเป็นไฟล์ชั่วคราว
ใช้ --token-from-ssh เพื่ออ่าน Token เดิมของ POS-B001-01 ผ่าน SSH alias jeterp แล้วถามเฉพาะ PIN
ขั้นตอน: ping/sync สินค้า -> ล็อกอิน PIN -> เปิดกะ -> ขาย 3 บิล -> sync -> นำเงินออก -> ปิดกะ -> sync

บิลที่ขายจะเข้า ยอดขาย/สต็อก/บัญชีจริง ต้องล้างก่อนเปิดขายจริง
"""
from __future__ import annotations

import argparse
import getpass
import json
import os
import re
import subprocess
import sys
import tempfile
import uuid
from decimal import Decimal
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from pos_python.api_client import LaravelPosClient
from pos_python.database import connect
from pos_python.provisioning import ProvisioningService
from pos_python.services import CartLine, PosService
from pos_python.sync_service import SyncService


def existing_device_token() -> str:
    """Read the existing token over SSH, keeping both output streams out of logs."""
    query = r'''<?php
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $devices = App\Models\PosDevice::where('terminal_code', 'POS-B001-01')
        ->whereNull('revoked_at')->get();
    if ($devices->count() !== 1) {
        throw new RuntimeException('Device is not unique');
    }
    $device = $devices->first();
    $token = $device->token_encrypted;
    if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{48}$/', $token)
        || ! hash_equals($device->token_hash, App\Models\PosDevice::hashToken($token))) {
        throw new RuntimeException('Existing token is unavailable');
    }
    echo json_encode(['token' => $token], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, "Cannot read existing device token\n");
    exit(1);
}
'''
    try:
        result = subprocess.run(
            ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=8", "jeterp",
             "cd /var/www/jeterp && php"],
            input=query, text=True, capture_output=True, timeout=30, check=False,
        )
    except (OSError, subprocess.SubprocessError):
        raise RuntimeError("อ่าน Token เดิมผ่าน SSH ไม่สำเร็จ ไม่มีการออก Token ใหม่") from None
    if result.returncode != 0:
        # Never include SSH/PHP output: it may contain credential material.
        raise RuntimeError("อ่าน Token เดิมผ่าน SSH ไม่สำเร็จ ไม่มีการออก Token ใหม่")
    try:
        payload = json.loads(result.stdout)
        token = payload.get("token") if isinstance(payload, dict) else None
    except (ValueError, TypeError):
        token = None
    if not isinstance(token, str) or not re.fullmatch(r"[A-Za-z0-9]{48}", token):
        raise RuntimeError("รูปแบบ Token เดิมจาก SSH ไม่ถูกต้อง")
    return token


def step(ok: bool, text: str) -> None:
    print(f"{'✔' if ok else '✘'} {text}")
    if not ok:
        raise SystemExit(1)


def verify_device(profile: dict) -> tuple[int, str]:
    terminal = (profile.get("device") or {}).get("terminal_code")
    step(terminal == "POS-B001-01" and bool(profile.get("branch_id")),
         f"เครื่องทดสอบต้องเป็น POS-B001-01 (ERP คืน {terminal or '-'})")
    return int(profile["branch_id"]), terminal


def login_cashier(prov: ProvisioningService, pin: str, code: str) -> dict:
    login = prov.online_cashier_login(pin, cashier_code=code)
    if login.get("selection_required"):
        matches = [c for c in login.get("cashiers", [])
                   if str(c.get("code", "")).upper() == code.upper()]
        step(len(matches) == 1, f"ต้องพบคนขายรหัส {code} เพียงคนเดียว หยุดหากรหัสไม่ตรง")
        login = prov.online_cashier_login(pin, cashier_server_id=int(matches[0]["id"]))
    cashier = login.get("cashier") or {}
    step(bool(cashier.get("id")) and str(cashier.get("code", "")).upper() == code.upper(),
         f"ยืนยันคนขายตรงรหัส {code}")
    step(not login.get("must_change_pin") and not cashier.get("must_change_pin"),
         "PIN พร้อมใช้ (หากต้องเปลี่ยน PIN ให้เจ้าของเปลี่ยนเองก่อนทดสอบ)")
    return login


def verify_no_open_shift(api: LaravelPosClient, branch: int, cashier: int) -> None:
    current = api.get(f"/api/pos/shift?branch_id={branch}&cashier_id={cashier}")
    step("shift" in current and current["shift"] is None,
         "ไม่มีกะเดิมเปิดอยู่ — หยุดแทนการขายหรือปิดกะเดิม")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--token-from-ssh", action="store_true",
                        help="อ่าน Token เดิมผ่าน SSH alias jeterp โดยไม่แสดงหรือบันทึกค่า")
    args = parser.parse_args(argv)
    base = os.environ.get("POS_SMOKE_URL", "https://erp.popstarcenter.com")
    code = os.environ.get("POS_SMOKE_CASHIER", "B001")
    if args.token_from_ssh and base.rstrip("/") != "https://erp.popstarcenter.com":
        sys.exit("Token production ใช้ได้เฉพาะ https://erp.popstarcenter.com")
    print(f"ERP: {base}  คนขาย: {code}")
    if args.token_from_ssh:
        try:
            token = existing_device_token()
        except RuntimeError as error:
            sys.exit(str(error))
        print("อ่าน Token เดิมของ POS-B001-01 ผ่าน SSH แล้ว (ไม่แสดงค่า)")
    else:
        token = getpass.getpass("Device Token (ไม่แสดงตัวอักษร): ").strip()
    pin = getpass.getpass(f"PIN ของ {code}: ").strip()
    if not token or not pin:
        sys.exit("ต้องใส่ทั้ง Token และ PIN")

    db = connect(Path(tempfile.mkdtemp()) / "smoke.sqlite")
    api = LaravelPosClient(base, token, allow_insecure=False)
    prov, pos, sync = ProvisioningService(db, api), PosService(db), SyncService(db, api)

    profile = prov.ping()
    branch, terminal = verify_device(profile)
    step(True, f"เชื่อม ERP ได้ สาขา id={branch} เครื่อง {terminal}")
    prov.sync_down(branch)
    products = db.execute(
        "SELECT id, name, price FROM products WHERE active=1 AND server_id IS NOT NULL "
        "AND CAST(price AS REAL) > 0 ORDER BY id LIMIT 3").fetchall()
    step(len(products) == 3, f"ดึงสินค้าได้ {db.execute('SELECT count(*) FROM products').fetchone()[0]} รายการ")
    price = lambda p: Decimal(str(p["price"]))  # noqa: E731

    login = login_cashier(prov, pin, code)
    step(True, f"ล็อกอิน {login['cashier'].get('name', '-')}")
    server_cashier, cashier = login["cashier"]["id"], login["local_cashier_id"]

    verify_no_open_shift(api, branch, server_cashier)
    shift = pos.open_shift(branch, terminal, cashier, Decimal("1000"))
    shift_no = prov.open_server_shift(branch_id=branch, cashier_server_id=server_cashier,
                                      opening_cash=Decimal("1000"), local_shift_id=shift)
    step(True, f"เปิดกะ server id={shift_no} เงินทอน 1,000")

    sales = []

    def sell(tag: str, lines: list[CartLine], method: str = "cash", paid: Decimal | None = None, **extra) -> None:
        su = str(uuid.uuid4())
        total = sum((line.qty * line.unit_price for line in lines), Decimal("0"))
        pos.checkout(document_no=f"SMOKE-{tag}-{su[:6]}", branch_id=branch, terminal_id=terminal, shift_id=shift,
                     cashier_id=cashier, lines=lines, payment_method=method,
                     paid_amount=paid if paid is not None else total, sale_uuid=su, **extra)
        sales.append((tag, su, total))

    p1, p2, p3 = products
    sell("เงินสดหลายรายการ", [CartLine(p1["id"], Decimal("2"), price(p1)), CartLine(p2["id"], Decimal("1"), price(p2))],
         paid=Decimal("5000"))
    sell("เงินสดรายการเดียว", [CartLine(p3["id"], Decimal("1"), price(p3))])
    sell("โอนเงิน", [CartLine(p2["id"], Decimal("1"), price(p2))], method="transfer",
         payment_confirmed=True, transfer_account_last4="0000", payment_reference="SMOKE-TRF")

    sync.sync_pending_sales()
    for tag, su, total in sales:
        row = db.execute("SELECT sync_status, server_receipt_no FROM sales WHERE sale_uuid=?", (su,)).fetchone()
        step(row["sync_status"] == "synced", f"บิล{tag} {total:,.2f} บาท -> ERP {row['server_receipt_no'] or row['sync_status']}")

    pos.record_cash_movement(shift_id=shift, movement_type="payout", amount=Decimal("20"), reason="ทดสอบระบบ")
    summary = pos.shift_cash_summary(shift)
    pos.close_shift(shift_id=shift, counted_cash=summary.expected_cash, closing_note="ปิดกะทดสอบระบบ")
    sync.sync_pending_sales()
    rows = db.execute("SELECT aggregate_type, status FROM sync_outbox "
                      "WHERE aggregate_type IN ('cash_movement','shift_close')").fetchall()
    step(bool(rows) and all(r["status"] == "synced" for r in rows),
         f"นำเงินออก + ปิดกะ (เงินในลิ้นชัก {summary.expected_cash:,.2f}) ส่งขึ้น ERP")
    shift_no = db.execute("SELECT server_id FROM shifts WHERE id=?", (shift,)).fetchone()["server_id"]
    print(f"\nเสร็จ: กะ server id={shift_no} ดูที่ ERP > POS > กะ/บิล แล้วแจ้งผลเพื่อล้างข้อมูลทดสอบ")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
