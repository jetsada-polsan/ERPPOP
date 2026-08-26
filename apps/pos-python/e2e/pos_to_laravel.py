"""e2e: ขายจริงจาก POS Python → Laravel API → ให้ ERP ลงบิล/สต๊อก/GL

รันโดย .github/workflows/pos-e2e.yml หลังบูต Laravel + ออก device token
อ่านค่าเชื่อมต่อจาก env:
    POS_E2E_URL    ที่อยู่ Laravel (เช่น http://127.0.0.1:8123)
    POS_E2E_TOKEN  device token จาก `php artisan pos:e2e-fixture`
    POS_E2E_PIN    PIN ของ cashier (ดีฟอลต์ 1234)

พิสูจน์ฝั่ง client: ping/sync ลง/login/เปิดกะออนไลน์/ขาย/ส่งขึ้นจนได้ receipt_no
ส่วนการลง GL/สต๊อกให้ครบและดุล ตรวจต่อด้วย `php artisan uat:reconcile` ใน workflow
สคริปต์นี้ exit ไม่ศูนย์ถ้าขั้นไหนพลาด เพื่อให้ CI แดง
"""
from __future__ import annotations

import os
import sys
import tempfile
import uuid
from decimal import Decimal
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from pos_python.api_client import LaravelPosClient
from pos_python.database import connect
from pos_python.provisioning import ProvisioningService
from pos_python.services import CartLine, PosService, verify_offline_credential
from pos_python.sync_service import SyncService


def need(name: str) -> str:
    value = os.environ.get(name)
    if not value:
        sys.exit(f"ขาด env {name} — workflow ต้องส่งค่าเชื่อมต่อมาก่อน")
    return value


def main() -> int:
    base = need("POS_E2E_URL")
    token = need("POS_E2E_TOKEN")
    pin = os.environ.get("POS_E2E_PIN", "1234")

    db = connect(Path(tempfile.mkdtemp()) / "e2e.sqlite")
    api = LaravelPosClient(base, token, allow_insecure=base.startswith("http://"))
    prov = ProvisioningService(db, api)
    pos = PosService(db)
    sync = SyncService(db, api)

    print("1) ping")
    profile = prov.ping()
    branch = profile["branch_id"]
    terminal = (profile.get("device") or {}).get("terminal_code")
    assert branch, "ping ไม่คืน branch_id — เครื่องยังไม่ผูกสาขา"
    print(f"   branch={branch} terminal={terminal}")

    print("2) sync ลง (catalog + cashiers)")
    down = prov.sync_down(branch)
    assert down["catalog"]["upserted"] > 0, "ไม่มีสินค้าถูกดึงลงมา"
    prod = db.execute(
        "SELECT id, server_id, price FROM products WHERE active=1 AND server_id IS NOT NULL AND CAST(price AS REAL) > 0 LIMIT 1"
    ).fetchone()
    assert prod, "ไม่มีสินค้าที่มีราคาให้ขาย"
    print(f"   catalog={down['catalog']} cashiers={down['cashiers']}")

    print("3) cashier login (PIN)")
    login = prov.online_cashier_login(pin)
    if login.get("selection_required"):
        login = prov.online_cashier_login(pin, int(login["cashiers"][0]["id"]))
    assert not login["selection_required"], "login ยังค้างที่ selection"
    server_cashier = login["cashier"]["id"]
    local_cashier = login["local_cashier_id"]
    cred = db.execute("SELECT cred_salt, cred_verifier, cred_iterations FROM local_cashiers WHERE id=?", (local_cashier,)).fetchone()
    assert cred["cred_salt"], "ไม่ได้เก็บ offline credential"
    assert verify_offline_credential(pin, cred["cred_salt"], cred["cred_verifier"], cred["cred_iterations"]), \
        "credential ที่เก็บตรวจ PIN ออฟไลน์ไม่ผ่าน"
    print(f"   server_cashier={server_cashier} · ตรวจ PIN ออฟไลน์ผ่าน")

    print("4) เปิดกะ local + ผูก server_shift_id")
    local_shift = pos.open_shift(branch, terminal, local_cashier, Decimal("1000"))
    server_shift = prov.open_server_shift(branch_id=branch, cashier_server_id=server_cashier,
                                          opening_cash=Decimal("1000"), local_shift_id=local_shift)
    assert db.execute("SELECT server_id FROM shifts WHERE id=?", (local_shift,)).fetchone()["server_id"] == server_shift
    print(f"   local_shift={local_shift} → server_shift={server_shift}")

    print("5) ขาย 1 บิล (2 ชิ้น)")
    su = str(uuid.uuid4())
    pos.checkout(document_no="E2E-" + su[:8], branch_id=branch, terminal_id=terminal,
                 shift_id=local_shift, cashier_id=local_cashier,
                 lines=[CartLine(prod["id"], Decimal("2"), Decimal(str(prod["price"])))],
                 payment_method="cash", paid_amount=Decimal("100000"), sale_uuid=su)
    assert pos.pending_sync_count() == 1, "บิลไม่เข้าคิว sync"

    print("6) sync ขึ้น → /api/pos/checkout")
    result = sync.sync_pending_sales()
    assert result == {"synced": 1, "failed": 0}, f"sync ไม่สำเร็จ: {result}"
    row = db.execute("SELECT sync_status, server_receipt_no FROM sales WHERE sale_uuid=?", (su,)).fetchone()
    assert row["sync_status"] == "synced", f"สถานะบิลไม่ใช่ synced: {row['sync_status']}"
    assert row["server_receipt_no"], "ERP ไม่คืน receipt_no"
    print(f"   receipt_no={row['server_receipt_no']}")

    print("\nPASS: ขาย Python → Laravel สำเร็จ, ได้ receipt " + row["server_receipt_no"])
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except AssertionError as error:
        print("FAIL:", error)
        raise SystemExit(1)
