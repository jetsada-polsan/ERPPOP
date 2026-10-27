"""e2e เงื่อนไขขายของ POS Python ที่ยิงเข้า Laravel จริง (ต่อจาก pos_to_laravel.py)

pos_to_laravel.py พิสูจน์แค่บิลเงินสดบิลเดียว เงื่อนไขอื่นมีแต่ unit test กับ API จำลอง
สคริปต์นี้ไล่ทุกเงื่อนไขที่หน้าร้านใช้จริงก่อน go-live ผ่าน client ตัวเดียวกับเครื่องขาย:

  A เงินสดหลายรายการ + ทอนเงิน        B โอนเงิน (ยืนยันรับเงินแล้ว)
  C QR พร้อมเพย์ (ยืนยันแล้ว)          D ลดราคาโดยมีผู้อนุมัติ
  E ลดราคาไม่มีผู้อนุมัติ -> ต้องถูกปฏิเสธที่เครื่อง
  F ส่งบิลเดิมซ้ำ -> ERP ต้องไม่สร้างบิลซ้ำ
  G ยกเลิกบิลที่ส่งขึ้นแล้ว -> ERP ต้องบันทึกยกเลิก
  H เปิดกะตอนออฟไลน์ ขาย แล้วค่อยต่อเน็ต -> กะต้องขึ้นก่อนบิล
  I นำเงินออก/จ่ายเงินสด + ปิดกะนับเงิน -> ERP ต้องได้ยอดตรง
  J ขายเข้ากะที่ปิดแล้ว -> ต้องถูกปฏิเสธ

env เดียวกับ pos_to_laravel.py: POS_E2E_URL, POS_E2E_TOKEN, POS_E2E_PIN
ผลสรุปเขียนเป็น JSON ที่ POS_E2E_REPORT (ถ้าระบุ) ให้ฝั่ง Laravel ตรวจต่อ
"""
from __future__ import annotations

import json
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
from pos_python.services import CartLine, PosService
from pos_python.sync_service import SyncService

RESULTS: list[tuple[str, bool, str]] = []


def need(name: str) -> str:
    value = os.environ.get(name)
    if not value:
        sys.exit(f"ขาด env {name}")
    return value


def record(name: str, ok: bool, detail: str = "") -> None:
    RESULTS.append((name, ok, detail))
    print(f"{'PASS' if ok else 'FAIL'}  {name}  {detail}")


def main() -> int:
    base, token, pin = need("POS_E2E_URL"), need("POS_E2E_TOKEN"), os.environ.get("POS_E2E_PIN", "1234")
    db = connect(Path(tempfile.mkdtemp()) / "conditions.sqlite")
    api = LaravelPosClient(base, token, allow_insecure=base.startswith("http://"))
    prov, pos, sync = ProvisioningService(db, api), PosService(db), SyncService(db, api)

    profile = prov.ping()
    branch = profile["branch_id"]
    terminal = (profile.get("device") or {}).get("terminal_code")
    prov.sync_down(branch)
    products = db.execute(
        "SELECT id, server_id, price FROM products WHERE active=1 AND server_id IS NOT NULL "
        "AND CAST(price AS REAL) > 0 ORDER BY id LIMIT 3"
    ).fetchall()
    if len(products) < 3:
        sys.exit("ต้องมีสินค้าที่มีราคาอย่างน้อย 3 รายการ")
    p1, p2, p3 = products
    price = lambda p: Decimal(str(p["price"]))  # noqa: E731

    login = prov.online_cashier_login(pin)
    if login.get("selection_required"):
        login = prov.online_cashier_login(pin, int(login["cashiers"][0]["id"]))
    server_cashier, cashier = login["cashier"]["id"], login["local_cashier_id"]

    shift = pos.open_shift(branch, terminal, cashier, Decimal("1000"))
    prov.open_server_shift(branch_id=branch, cashier_server_id=server_cashier,
                           opening_cash=Decimal("1000"), local_shift_id=shift)

    def sell(tag: str, lines: list[CartLine], method: str = "cash", paid: Decimal | None = None, **extra) -> str:
        su = str(uuid.uuid4())
        total = sum((line.qty * line.unit_price - line.discount for line in lines), Decimal("0"))
        pos.checkout(document_no=f"{tag}-{su[:8]}", branch_id=branch, terminal_id=terminal, shift_id=shift,
                     cashier_id=cashier, lines=lines, payment_method=method,
                     paid_amount=paid if paid is not None else total, sale_uuid=su, **extra)
        return su

    def synced(su: str) -> tuple[str, str | None]:
        row = db.execute("SELECT sync_status, server_receipt_no FROM sales WHERE sale_uuid=?", (su,)).fetchone()
        return row["sync_status"], row["server_receipt_no"]

    receipts: dict[str, str | None] = {}

    # A เงินสดหลายรายการ + ทอน
    a = sell("A", [CartLine(p1["id"], Decimal("2"), price(p1)), CartLine(p2["id"], Decimal("1"), price(p2))],
             paid=Decimal("10000"))
    # B โอนเงิน
    b = sell("B", [CartLine(p1["id"], Decimal("1"), price(p1))], method="transfer",
             payment_confirmed=True, transfer_account_last4="1234", payment_reference="TRF-E2E")
    # C QR พร้อมเพย์: หน้าจอจริงส่งเป็น transfer + qr_payload (ui.py ไม่มีวิธีชำระชื่อ qr)
    c = sell("C", [CartLine(p2["id"], Decimal("1"), price(p2))], method="transfer", payment_confirmed=True,
             qr_payload="000201010212E2E", payment_reference="QR-E2E", transfer_account_last4="5678")
    # D ลดราคามีผู้อนุมัติ: ราคาลด 1 บาทจากราคาตั้ง
    lowered = price(p3) - Decimal("1")
    d = sell("D", [CartLine(p3["id"], Decimal("1"), lowered, list_price=price(p3))],
             adjustment_approved_by="manager-e2e")

    # E ลดราคาไม่มีผู้อนุมัติ -> ต้องถูกปฏิเสธที่เครื่อง ไม่เข้าคิว
    before = pos.pending_sync_count()
    try:
        sell("E", [CartLine(p3["id"], Decimal("1"), lowered, list_price=price(p3))])
        record("E ลดราคาไม่มีผู้อนุมัติถูกปฏิเสธ", False, "เครื่องยอมออกบิล")
    except ValueError as error:
        record("E ลดราคาไม่มีผู้อนุมัติถูกปฏิเสธ", pos.pending_sync_count() == before, str(error))

    result = sync.sync_pending_sales()
    for tag, su in (("A เงินสดหลายรายการ+ทอน", a), ("B โอนเงิน", b), ("C QR พร้อมเพย์", c), ("D ลดราคามีผู้อนุมัติ", d)):
        status, receipt = synced(su)
        receipts[tag[0]] = receipt
        record(tag, status == "synced" and bool(receipt), f"{status} {receipt or ''}")
    if result.get("review"):
        record("ไม่มีบิลค้างรอตรวจ (review)", False, str(result))

    # F ส่งบิลเดิมซ้ำ: เอาสถานะกลับเป็น pending แล้ว sync ใหม่ ERP ต้องคืนเลขเดิม
    db.execute("UPDATE sync_outbox SET status='pending', next_attempt_at=NULL WHERE aggregate_uuid=?", (a,))
    db.execute("UPDATE sales SET sync_status='pending' WHERE sale_uuid=?", (a,))
    db.commit()
    sync.sync_pending_sales()
    status, again = synced(a)
    record("F ส่งบิลซ้ำได้เลขเดิม ไม่เกิดบิลใหม่", again == receipts["A"], f"{receipts['A']} -> {again}")

    # G ยกเลิกบิล B ที่ขึ้นแล้ว
    sale_b = db.execute("SELECT id FROM sales WHERE sale_uuid=?", (b,)).fetchone()["id"]
    pos.void_sale(sale_b, cashier_id=cashier, reason="ลูกค้ายกเลิก (e2e)")
    sync.sync_pending_sales()
    void_row = db.execute("SELECT status FROM sync_outbox WHERE aggregate_type='sale_void' ORDER BY id DESC LIMIT 1").fetchone()
    record("G ยกเลิกบิลส่งขึ้น ERP", bool(void_row) and void_row["status"] == "synced",
           void_row["status"] if void_row else "ไม่มีคิวยกเลิก")

    # I เงินสดเข้า/ออก + ปิดกะ
    pos.record_cash_movement(shift_id=shift, movement_type="drop", amount=Decimal("500"), reason="นำส่งเงิน (e2e)")
    pos.record_cash_movement(shift_id=shift, movement_type="payout", amount=Decimal("40"), reason="ค่าน้ำแข็ง (e2e)")
    summary = pos.shift_cash_summary(shift)
    pos.close_shift(shift_id=shift, counted_cash=summary.expected_cash, closing_note="ปิดกะ e2e")
    sync.sync_pending_sales()
    pending = db.execute(
        "SELECT aggregate_type, status FROM sync_outbox WHERE aggregate_type IN ('cash_movement','shift_close')"
    ).fetchall()
    record("I เงินสดออก/จ่าย + ปิดกะส่งขึ้น ERP", bool(pending) and all(r["status"] == "synced" for r in pending),
           ", ".join(f"{r['aggregate_type']}={r['status']}" for r in pending))

    # J ขายเข้ากะที่ปิดแล้ว
    try:
        sell("J", [CartLine(p1["id"], Decimal("1"), price(p1))])
        record("J ขายเข้ากะที่ปิดแล้วถูกปฏิเสธ", False, "เครื่องยอมออกบิล")
    except ValueError as error:
        record("J ขายเข้ากะที่ปิดแล้วถูกปฏิเสธ", True, str(error))

    # H เปิดกะตอนออฟไลน์ (ไม่ผูก server) ขาย แล้วค่อย sync
    offline_shift = pos.open_shift(branch, terminal, cashier, Decimal("500"))
    pos.queue_shift_open(offline_shift)
    shift = offline_shift
    h = sell("H", [CartLine(p2["id"], Decimal("1"), price(p2))])
    sync.sync_pending_sales()
    status, receipt = synced(h)
    server_shift = db.execute("SELECT server_id FROM shifts WHERE id=?", (offline_shift,)).fetchone()["server_id"]
    record("H เปิดกะออฟไลน์แล้ว sync ภายหลัง", bool(server_shift) and status == "synced",
           f"server_shift={server_shift} bill={status} {receipt or ''}")
    receipts["H"] = receipt

    report = {
        "branch_id": branch,
        "receipts": receipts,
        "voided_receipt": receipts.get("B"),
        "results": [{"name": n, "ok": ok, "detail": d} for n, ok, d in RESULTS],
    }
    if os.environ.get("POS_E2E_REPORT"):
        Path(os.environ["POS_E2E_REPORT"]).write_text(json.dumps(report, ensure_ascii=False, indent=2))

    failed = [n for n, ok, _ in RESULTS if not ok]
    print(f"\nสรุป: ผ่าน {len(RESULTS) - len(failed)}/{len(RESULTS)}")
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
