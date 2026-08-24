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
        self.db.execute("INSERT INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (77, 'POP001', 'Tester', ?, ?)", (pin_hash('1234'), now()))
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
        self.assertEqual((payload["shift_id"], payload["cashier_id"], payload["items"][0]["product_id"]), (500, 77, 101))
        self.assertEqual((payload["items"][0]["barcode"], payload["items"][0]["barcode_type"]), ("8001230125503", "SCALE_WEIGHT"))
        self.assertEqual(self.db.execute("SELECT status FROM sync_outbox").fetchone()[0], "synced")

    def test_keeps_sale_when_offline_shift_has_no_server_mapping(self) -> None:
        sale_uuid = self.sale()
        api = FakeApi()
        with self.assertRaisesRegex(RuntimeError, "server_shift_id"):
            SyncService(self.db, api).sync_sale(sale_uuid)
        self.assertEqual(api.calls, [])
        row = self.db.execute("SELECT status, last_error FROM sync_outbox").fetchone()
        self.assertEqual(row["status"], "failed")
        self.assertIn("เปิดกะออนไลน์", row["last_error"])

    def test_retries_failed_queue_without_creating_new_local_sale(self) -> None:
        sale_uuid = self.sale()
        self.pos.bind_server_shift(self.shift_id, 500)
        api = FakeApi({"success": False, "message": "temporary server validation"})
        sync = SyncService(self.db, api)
        self.assertEqual(sync.sync_pending_sales(), {"synced": 0, "failed": 1})
        api.response = {"success": True, "receipt_no": "PS-HQ-000002"}
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


if __name__ == "__main__":
    unittest.main()
