from __future__ import annotations

import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock

from PySide6.QtWidgets import QApplication

from pos_python.api_client import LaravelApiError
from pos_python.database import connect
from pos_python.device_status import collect
from pos_python.provisioning import ProvisioningService
from pos_python.services import CartLine, PosService, now, pin_hash
from pos_python.sync_service import SyncService
from pos_python.ui import run_ui


class RecordingApi:
    def __init__(self, responses: dict[str, dict] | None = None, fail_on_sale_index: int | None = None):
        self.calls: list[tuple[str, dict, str | None]] = []
        self.responses = responses or {}
        self.fail_on_sale_index = fail_on_sale_index
        self._sale_count = 0

    def post(self, path: str, payload: dict, *, idempotency_key: str | None = None) -> dict:
        self.calls.append((path, payload, idempotency_key))
        if path == "/api/pos/checkout":
            self._sale_count += 1
            if self.fail_on_sale_index is not None and self._sale_count == self.fail_on_sale_index:
                raise RuntimeError("Network timeout simulating failed sale sync")
        if path in self.responses:
            resp = self.responses[path]
            if isinstance(resp, Exception):
                raise resp
            return resp
        if path == "/api/pos/shift/open":
            return {"success": True, "shift": {"id": 888}}
        if path == "/api/pos/shift/close":
            return {"success": True, "shift": {"id": payload.get("shift_id", 888)}}
        return {"success": True, "receipt_no": f"PS-{len(self.calls)}"}


class OfflineCloseShiftTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.db.execute(
            "INSERT INTO local_cashiers (id, server_id, code, name, pin_hash, synced_at) VALUES (77, 900, 'POP001', 'Tester', ?, ?)",
            (pin_hash("1234"), now()),
        )
        self.db.execute(
            "INSERT INTO products (id, server_id, sku, name, unit_name, updated_at) VALUES (1, 101, 'P000001', 'หมูสด', 'กก.', ?)",
            (now(),),
        )
        self.db.commit()
        self.pos = PosService(self.db)
        self.shift_id = self.pos.open_shift(1, "HQ-01", 77, Decimal("100"))

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def make_sale(self, uuid: str, doc_no: str = "PY-0001") -> str:
        self.pos.checkout(
            document_no=doc_no,
            branch_id=1,
            terminal_id="HQ-01",
            shift_id=self.shift_id,
            cashier_id=77,
            lines=[CartLine(1, Decimal("1"), Decimal("50"))],
            payment_method="cash",
            paid_amount=Decimal("50"),
            sale_uuid=uuid,
        )
        return uuid

    def test_offline_close_shift_sets_closed_and_queues_shift_close_in_outbox(self) -> None:
        """ข้อ 1: ออฟไลน์ปิดกะได้ กะเป็น closed และมีคิว shift_close ใน sync_outbox"""
        summary = self.pos.close_shift(shift_id=self.shift_id, counted_cash=Decimal("150"), closing_note="ปิดกะออฟไลน์")
        self.assertEqual(summary.counted_cash, Decimal("150.00"))
        self.assertEqual(summary.cash_difference, Decimal("50.00"))

        shift_row = self.db.execute("SELECT status, closed_at, counted_cash, cash_difference FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()
        self.assertEqual(shift_row["status"], "closed")
        self.assertIsNotNone(shift_row["closed_at"])
        self.assertEqual(shift_row["counted_cash"], "150.00")

        outbox_row = self.db.execute("SELECT aggregate_type, aggregate_uuid, status, attempts FROM sync_outbox WHERE aggregate_type = 'shift_close'").fetchone()
        self.assertIsNotNone(outbox_row)
        self.assertEqual(outbox_row["aggregate_type"], "shift_close")
        self.assertEqual(outbox_row["status"], "pending")
        self.assertEqual(outbox_row["attempts"], 0)

    def test_pending_sales_delay_shift_close_until_all_synced(self) -> None:
        """ข้อ 2: มีบิลค้าง 2 ใบ -> ปิดกะได้ -> รอบที่บิลใบหนึ่งยังส่งไม่ผ่าน ต้องไม่เรียก /api/pos/shift/close
        และ attempts ไม่เพิ่ม -> รอบถัดมาบิลผ่านครบ จึงเรียก /api/pos/shift/close หลัง /api/pos/checkout ทุกใบ"""
        self.pos.bind_server_shift(self.shift_id, 500)
        self.make_sale("sale-bill-1", "PY-0001")
        self.make_sale("sale-bill-2", "PY-0002")

        # ปิดกะได้แม้มีบิลค้าง 2 ใบ
        self.pos.close_shift(shift_id=self.shift_id, counted_cash=Decimal("200"))
        self.assertEqual(self.db.execute("SELECT status FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()["status"], "closed")

        # รอบ 1: บิลใบที่ 2 ส่งไม่ผ่าน (fail_on_sale_index=2)
        api_round1 = RecordingApi(fail_on_sale_index=2)
        sync1 = SyncService(self.db, api_round1)
        res1 = sync1.sync_pending_sales()

        # ต้องไม่เรียก /api/pos/shift/close
        endpoints1 = [call[0] for call in api_round1.calls]
        self.assertNotIn("/api/pos/shift/close", endpoints1)
        self.assertIn("/api/pos/checkout", endpoints1)

        # attempts ของ shift_close ต้องไม่เพิ่ม และไม่ถูกนับเป็น failed
        outbox_shift_close = self.db.execute("SELECT status, attempts FROM sync_outbox WHERE aggregate_type = 'shift_close'").fetchone()
        self.assertEqual(outbox_shift_close["status"], "pending")
        self.assertEqual(outbox_shift_close["attempts"], 0)

        # ยังมีบิลที่ 2 ค้างอยู่
        pending_count = self.db.execute("SELECT count(*) FROM sales WHERE shift_id = ? AND sync_status NOT IN ('synced', 'resolved')", (self.shift_id,)).fetchone()[0]
        self.assertEqual(pending_count, 1)

        # จำลองเวลารอ retry ผ่านไป: ล้าง next_attempt_at
        self.db.execute("UPDATE sync_outbox SET next_attempt_at = NULL")
        self.db.commit()

        # รอบ 2: ทุกใบผ่าน
        api_round2 = RecordingApi()
        sync2 = SyncService(self.db, api_round2)
        res2 = sync2.sync_pending_sales()

        endpoints2 = [call[0] for call in api_round2.calls]
        self.assertIn("/api/pos/checkout", endpoints2)
        self.assertIn("/api/pos/shift/close", endpoints2)

        # ตรวจสอบว่า /api/pos/shift/close ถูกเรียกหลัง /api/pos/checkout
        checkout_idx = endpoints2.index("/api/pos/checkout")
        close_idx = endpoints2.index("/api/pos/shift/close")
        self.assertGreater(close_idx, checkout_idx, "shift/close ต้องถูกเรียกหลัง checkout")

        # บิลและกะปิดสมบูรณ์
        self.assertEqual(self.db.execute("SELECT count(*) FROM sales WHERE shift_id = ? AND sync_status != 'synced'", (self.shift_id,)).fetchone()[0], 0)
        self.assertEqual(self.db.execute("SELECT status FROM sync_outbox WHERE aggregate_type = 'shift_close'").fetchone()["status"], "synced")

    def test_offline_opened_shift_sync_order(self) -> None:
        """ข้อ 3: กะที่เปิดตอนออฟไลน์ ลำดับเรียกต้องเป็น shift/open -> checkout -> shift/close"""
        # ปรับกะแรกให้จบไปก่อน
        self.pos.close_shift(shift_id=self.shift_id, counted_cash=Decimal("100"))
        self.db.execute("DELETE FROM sync_outbox")  # เคลียร์คิวเดิมเพื่อทดสอบกะออฟไลน์ล้วนๆ
        self.db.commit()

        # สร้างกะใหม่ที่เปิดแบบออฟไลน์ (server_id เป็น None)
        offline_shift_id = self.pos.open_shift(1, "HQ-01", 77, Decimal("0"))

        # สร้าง outbox shift_open
        shift_row = self.db.execute("SELECT uuid FROM shifts WHERE id = ?", (offline_shift_id,)).fetchone()
        self.db.execute(
            """INSERT INTO sync_outbox (aggregate_type, aggregate_uuid, payload, priority, created_at)
               VALUES ('shift_open', ?, ?, 1, ?)""",
            (f"shift:{shift_row['uuid']}:open", json.dumps({
                "shift_uuid": shift_row["uuid"],
                "branch_id": 1,
                "cashier_server_id": 900,
                "opening_cash": "0.00",
            }), now()),
        )
        # สร้างบิลในกะออฟไลน์
        self.pos.checkout(
            document_no="OFFLINE-BILL-1",
            branch_id=1,
            terminal_id="HQ-01",
            shift_id=offline_shift_id,
            cashier_id=77,
            lines=[CartLine(1, Decimal("1"), Decimal("40"))],
            payment_method="cash",
            paid_amount=Decimal("40"),
            sale_uuid="sale-offline-bill-1",
        )
        # ปิดกะออฟไลน์
        self.pos.close_shift(shift_id=offline_shift_id, counted_cash=Decimal("40"))

        # ตอนนี้ใน sync_outbox มี: shift_open, sale, shift_close
        api = RecordingApi(responses={
            "/api/pos/shift/open": {"success": True, "shift": {"id": 777}},
            "/api/pos/checkout": {"success": True, "receipt_no": "PS-OFFLINE-01"},
            "/api/pos/shift/close": {"success": True, "shift": {"id": 777}},
        })
        SyncService(self.db, api).sync_pending_sales()

        called_endpoints = [call[0] for call in api.calls]
        self.assertEqual(called_endpoints, [
            "/api/pos/shift/open",
            "/api/pos/checkout",
            "/api/pos/shift/close",
        ], "ลำดับเรียกต้องเป็น shift/open -> checkout -> shift/close")

        # ตรวจสอบว่า server_id ของ shift ถูกอัปเดตเป็น 777
        saved_server_id = self.db.execute("SELECT server_id FROM shifts WHERE id = ?", (offline_shift_id,)).fetchone()[0]
        self.assertEqual(saved_server_id, 777)

    def test_needs_review_bill_holds_shift_close_until_resolved(self) -> None:
        """ข้อ 4: บิล needs_review ทำให้ปิดกะรอ พอ mark_resolved แล้ว ปิดกะส่งได้"""
        self.pos.bind_server_shift(self.shift_id, 500)
        review_uuid = self.make_sale("sale-review-1", "PY-REV-01")

        # ปิดกะได้
        self.pos.close_shift(shift_id=self.shift_id, counted_cash=Decimal("150"))

        # เมื่อ ERP ตอบ 422 needs_review
        class ReviewApi(RecordingApi):
            def post(self, path: str, payload: dict, *, idempotency_key: str | None = None) -> dict:
                self.calls.append((path, payload, idempotency_key))
                if path == "/api/pos/checkout":
                    raise LaravelApiError('HTTP 422: {"success":false,"message":"ราคาต่ำกว่าทุน","needs_review":true}')
                return super().post(path, payload, idempotency_key=idempotency_key)

        api1 = ReviewApi()
        sync1 = SyncService(self.db, api1)
        sync1.sync_pending_sales()

        # shift_close ต้องไม่ถูกเรียก
        self.assertNotIn("/api/pos/shift/close", [c[0] for c in api1.calls])
        # attempts ของ shift_close ต้องยังเป็น 0
        close_attempts = self.db.execute("SELECT attempts FROM sync_outbox WHERE aggregate_type = 'shift_close'").fetchone()[0]
        self.assertEqual(close_attempts, 0)

        # headline ต้องแสดงข้อความเตือนบิลที่ ERP ไม่รับ
        status = collect(self.db, server_url="http://pos.local", online=True)
        self.assertEqual(status.headline, "ปิดกะรอส่ง: รอบิลที่ ERP ไม่รับ 1 ใบ")

        # เมื่อผู้จัดการตรวจและเคลียร์ที่ ERP แล้ว (กลายเป็น resolved)
        self.db.execute("UPDATE sales SET sync_status = 'resolved' WHERE sale_uuid = ?", (review_uuid,))
        self.db.execute("UPDATE sync_outbox SET status = 'resolved' WHERE aggregate_uuid = ?", (review_uuid,))
        self.db.commit()

        # ซิงก์รอบถัดไป
        api2 = RecordingApi()
        sync2 = SyncService(self.db, api2)
        sync2.sync_pending_sales()

        # รอบนี้ shift_close ต้องถูกส่งขึ้น ERP
        self.assertIn("/api/pos/shift/close", [c[0] for c in api2.calls])
        close_status = self.db.execute("SELECT status FROM sync_outbox WHERE aggregate_type = 'shift_close'").fetchone()[0]
        self.assertEqual(close_status, "synced")

    def test_close_shift_dialog_ui_warnings_and_offline_banner(self) -> None:
        """ตรวจหน้า CloseShiftDialog: แสดงเตือนบิลค้าง/บิลติดตรวจ และไม่ขวางการกดปุ่มปิดกะ"""
        app = QApplication.instance() or QApplication([])
        api = Mock()
        api.get.return_value = {"shift": {"id": 500, "expected_cash": "150.00"}}
        api.post.return_value = {"success": True}
        online = SimpleNamespace(
            branch_id=1,
            terminal_id="HQ-01",
            profile={},
            online=False,
            provisioning=ProvisioningService(self.db, api),
            worker=SimpleNamespace(online=False, pending=0, stop=lambda: None),
        )
        window = run_ui(self.pos, online=online, app=app)
        window.shift_id = self.shift_id

        # สร้างบิลปกติ 1 ใบ และบิลติดตรวจ 1 ใบ
        self.make_sale("sale-pending", "DOC-01")
        self.make_sale("sale-review", "DOC-02")
        self.db.execute("UPDATE sales SET sync_status = 'needs_review' WHERE sale_uuid = 'sale-review'")
        self.db.commit()

        CloseShiftDialog = window.dialog_types["closing"]
        dialog = CloseShiftDialog(
            window,
            Decimal("150.00"),
            offline_message="ออฟไลน์อยู่: ปิดกะด้วยยอดในเครื่อง ระบบจะส่งขึ้น ERP เมื่อเน็ตกลับมา",
        )

        # ตรวจสอบ banner ออฟไลน์
        self.assertTrue(hasattr(dialog, "offline_banner"))
        self.assertIn("ออฟไลน์อยู่: ปิดกะด้วยยอดในเครื่อง", dialog.offline_banner.text())

        # ตรวจสอบข้อความเตือน
        warning_text = dialog.pending_warning.text()
        self.assertIn("มีบิลรอส่ง 1 ใบ จะส่งขึ้น ERP ก่อนการปิดกะอัตโนมัติ", warning_text)
        self.assertIn("มีบิลที่ ERP ไม่รับ 1 ใบ ผู้จัดการต้องตรวจที่ ERP", warning_text)

        # กรอกเงินสดจริง -> ปุ่มยืนยันปิดกะต้องกดได้ (ไม่ถูกบล็อกด้วยบิลค้าง)
        dialog.amount.setText("150.00")
        self.assertTrue(dialog.submit.isEnabled(), "ปุ่มปิดกะต้องเปิดใช้งานได้แม้มีบิลค้าง")

        # เจ้าของ 13 พ.ย.: จอเครื่อง 1366x768 (ลบ taskbar ~720) ปุ่มปิดกะต้องอยู่ในจอ ไม่ตกใต้ taskbar
        dialog.adjustSize()
        dialog.show()
        app.processEvents()
        bottom = dialog.submit.mapTo(dialog, dialog.submit.rect().bottomLeft()).y()
        self.assertLessEqual(dialog.sizeHint().height(), 680, "หน้าต่างปิดกะสูงเกินจอ")
        self.assertLessEqual(bottom, dialog.height())
        dialog.reject()
