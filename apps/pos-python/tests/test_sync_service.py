from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.database import connect
from pos_python.services import CartLine, PosService, now, pin_hash
from pos_python.sync_service import SyncService


class FakeApi:
    def __init__(self, response: dict | None = None):
        self.calls: list[tuple[str, dict, str | None]] = []
        self.response = response or {"success": True, "receipt_no": "PS-HQ-000001"}

    def post(self, path: str, payload: dict, *, idempotency_key: str | None = None) -> dict:
        self.calls.append((path, payload, idempotency_key))
        return self.response


class SyncServiceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.db.execute("INSERT INTO local_cashiers (id, server_id, code, name, pin_hash, synced_at) VALUES (77, 900, 'POP001', 'Tester', ?, ?)", (pin_hash('1234'), now()))
        self.db.execute("INSERT INTO products (id, server_id, sku, name, unit_name, updated_at) VALUES (1, 101, 'P000001', 'หมูสด', 'กก.', ?)", (now(),))
        self.db.commit()
        self.pos = PosService(self.db)
        self.shift_id = self.pos.open_shift(1, "HQ-01", 77, Decimal("0"))

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def sale(self) -> str:
        self.pos.checkout(document_no="PY-0001", branch_id=1, terminal_id="HQ-01", shift_id=self.shift_id, cashier_id=77,
            lines=[CartLine(1, Decimal("0.6275"), Decimal("200"), barcode="800123", source_barcode="8001230125503", barcode_type="SCALE_WEIGHT")],
            payment_method="cash", paid_amount=Decimal("125.50"), sale_uuid="sale-offline-1")
        return "sale-offline-1"

    def test_syncs_sale_with_server_ids_idempotency_key_and_raw_scale_label(self) -> None:
        sale_uuid = self.sale()
        self.pos.bind_server_shift(self.shift_id, 500)
        api = FakeApi()
        SyncService(self.db, api).sync_sale(sale_uuid)
        self.assertEqual(len(api.calls), 1)
        path, payload, key = api.calls[0]
        self.assertEqual((path, key), ("/api/pos/checkout", sale_uuid))
        self.assertEqual((payload["shift_id"], payload["cashier_id"], payload["items"][0]["product_id"]), (500, 900, 101))
        self.assertEqual((payload["items"][0]["barcode"], payload["items"][0]["barcode_type"]), ("8001230125503", "SCALE_WEIGHT"))
        self.assertEqual(self.db.execute("SELECT status FROM sync_outbox").fetchone()[0], "synced")

    def test_syncs_an_offline_shift_before_sales_are_uploaded(self) -> None:
        api = FakeApi({"success": True, "shift": {"id": 501}})
        self.pos.queue_shift_open(self.shift_id)

        SyncService(self.db, api).sync_pending_sales()

        shift = self.db.execute("SELECT server_id FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()
        queued = self.db.execute(
            "SELECT status FROM sync_outbox WHERE aggregate_type = 'shift_open'"
        ).fetchone()
        self.assertEqual(shift["server_id"], 501)
        self.assertEqual(queued["status"], "synced")
        self.assertEqual(api.calls[0][0], "/api/pos/shift/open")

    def test_keeps_sale_when_offline_shift_has_no_server_mapping(self) -> None:
        sale_uuid = self.sale()
        api = FakeApi()
        with self.assertRaisesRegex(RuntimeError, "server_shift_id"):
            SyncService(self.db, api).sync_sale(sale_uuid)
        self.assertEqual(api.calls, [])
        row = self.db.execute("SELECT status, last_error FROM sync_outbox").fetchone()
        self.assertEqual(row["status"], "failed")
        self.assertIn("เปิดกะออนไลน์", row["last_error"])

    def test_confirmed_qr_transfer_syncs_as_confirmed(self) -> None:
        sale_uuid = "sale-qr-confirmed"
        self.pos.checkout(
            document_no="PY-QR-0001", branch_id=1, terminal_id="HQ-01", shift_id=self.shift_id,
            cashier_id=77, lines=[CartLine(1, Decimal("1"), Decimal("125.50"))],
            payment_method="transfer", paid_amount=Decimal("125.50"), sale_uuid=sale_uuid,
            payment_reference="QR-HQ", qr_payload="PROMPTPAY", payment_confirmed=True,
        )
        self.pos.bind_server_shift(self.shift_id, 500)
        api = FakeApi()
        SyncService(self.db, api).sync_sale(sale_uuid)
        payload = api.calls[0][1]
        self.assertEqual(payload["method"], "transfer")
        self.assertTrue(payload["payment_confirmed"])
        self.assertEqual(payload["payment_ref"], "QR-HQ")

    def test_a_discounted_bill_reaches_erp_at_the_price_actually_paid(self) -> None:
        # ลด 20 บาทจาก 4 x 25 ลูกค้าจ่าย 80 — เดิมส่งขึ้น ERP เป็นราคาเต็ม 100 ไม่มีส่วนลด
        self.pos.checkout(document_no="PY-0002", branch_id=1, terminal_id="HQ-01", shift_id=self.shift_id, cashier_id=77,
            lines=[CartLine(1, Decimal("4"), Decimal("25"), discount=Decimal("20"))],
            payment_method="cash", paid_amount=Decimal("80"), sale_uuid="sale-discount", adjustment_approved_by="ผู้จัดการ ก")
        self.pos.bind_server_shift(self.shift_id, 500)
        api = FakeApi()
        SyncService(self.db, api).sync_sale("sale-discount")
        payload = api.calls[0][1]
        item = payload["items"][0]
        self.assertEqual(Decimal(item["qty"]) * Decimal(item["unit_price"]), Decimal("80"))
        self.assertEqual(payload["manual_discount_amount"], "20.00")
        self.assertEqual(payload["discount_approved_by"], "ผู้จัดการ ก")
        self.assertEqual(payload["cash_received"], "80.00")

    def test_a_lowered_price_is_sent_with_its_discount_from_the_list_price(self) -> None:
        self.pos.checkout(document_no="PY-0003", branch_id=1, terminal_id="HQ-01", shift_id=self.shift_id, cashier_id=77,
            lines=[CartLine(1, Decimal("3"), Decimal("20"), list_price=Decimal("25"))],
            payment_method="cash", paid_amount=Decimal("60"), sale_uuid="sale-lowered", adjustment_approved_by="ผู้จัดการ ก")
        self.pos.bind_server_shift(self.shift_id, 500)
        api = FakeApi()
        SyncService(self.db, api).sync_sale("sale-lowered")
        payload = api.calls[0][1]
        self.assertEqual(payload["items"][0]["unit_price"], "20")
        self.assertEqual(payload["manual_discount_amount"], "15.00")

    def test_a_full_price_bill_sends_no_discount_fields(self) -> None:
        sale_uuid = self.sale()
        self.pos.bind_server_shift(self.shift_id, 500)
        api = FakeApi()
        SyncService(self.db, api).sync_sale(sale_uuid)
        self.assertNotIn("manual_discount_amount", api.calls[0][1])

    def test_a_rejected_bill_waits_longer_after_each_failure(self) -> None:
        from datetime import datetime
        sale_uuid = self.sale()
        self.pos.bind_server_shift(self.shift_id, 500)
        sync = SyncService(self.db, FakeApi({"success": False, "message": "ราคาไม่ตรง"}))
        waits = []
        for _ in range(3):
            self.db.execute("UPDATE sync_outbox SET next_attempt_at = NULL WHERE aggregate_uuid = ?", (sale_uuid,))
            sync.sync_pending_sales()
            row = self.db.execute("SELECT next_attempt_at FROM sync_outbox WHERE aggregate_uuid = ?", (sale_uuid,)).fetchone()
            waits.append((datetime.fromisoformat(row["next_attempt_at"]) - datetime.now().astimezone()).total_seconds())
        self.assertTrue(waits[0] < waits[1] < waits[2], waits)
        self.assertLessEqual(max(waits), 15 * 60 + 5)

    def test_retries_failed_queue_without_creating_new_local_sale(self) -> None:
        sale_uuid = self.sale()
        self.pos.bind_server_shift(self.shift_id, 500)
        api = FakeApi({"success": False, "message": "temporary server validation"})
        sync = SyncService(self.db, api)
        self.assertEqual(sync.sync_pending_sales(), {"synced": 0, "failed": 1})
        api.response = {"success": True, "receipt_no": "PS-HQ-000002"}
        # บิลที่เพิ่งส่งไม่ผ่านต้องรอก่อน ไม่ยิงซ้ำทันที
        self.assertEqual(sync.sync_pending_sales(), {"synced": 0, "failed": 0})
        self.db.execute("UPDATE sync_outbox SET next_attempt_at = '2000-01-01T00:00:00+00:00'")
        self.assertEqual(sync.sync_pending_sales(), {"synced": 1, "failed": 0})
        self.assertEqual(self.db.execute("SELECT count(*) FROM sales").fetchone()[0], 1)

    def test_syncs_an_offline_void_after_its_sale_and_uses_the_server_receipt_number(self) -> None:
        sale_uuid = self.sale()
        self.pos.bind_server_shift(self.shift_id, 500)
        sale_id = self.db.execute("SELECT id FROM sales WHERE sale_uuid = ?", (sale_uuid,)).fetchone()[0]
        self.pos.void_sale(sale_id, cashier_id=77, reason="ลูกค้าเปลี่ยนใจ")
        api = FakeApi({"success": True, "receipt_no": "PS-HQ-000001"})

        self.assertEqual(SyncService(self.db, api).sync_pending_sales(), {"synced": 2, "failed": 0})
        self.assertEqual(api.calls[0][0], "/api/pos/checkout")
        self.assertEqual(api.calls[1][0], "/api/pos/receipt/void")
        self.assertEqual(api.calls[1][1]["receipt_no"], "PS-HQ-000001")
        self.assertEqual(api.calls[1][1]["shift_id"], 500)
        self.assertEqual(
            self.db.execute("SELECT status FROM sync_outbox WHERE aggregate_uuid = ?", (f"{sale_uuid}:void",)).fetchone()[0],
            "synced",
        )
        queued = self.db.execute(
            "SELECT priority, depends_on_uuid FROM sync_outbox WHERE aggregate_uuid = ?", (f"{sale_uuid}:void",)
        ).fetchone()
        self.assertEqual((queued["priority"], queued["depends_on_uuid"]), (3, sale_uuid))


if __name__ == "__main__":
    unittest.main()
