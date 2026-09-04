from __future__ import annotations

import base64
import hashlib
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from pathlib import Path

from pos_python.database import connect
from pos_python.mock_printer import print_receipt
from pos_python.services import CartLine, PosService, now, pin_hash


class PosServiceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        valid_until = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat()
        self.db.execute(
            """INSERT INTO local_cashiers (id, code, name, pin_hash, synced_at, last_synced_at, offline_valid_until)
            VALUES (1, 'POP001', 'Tester', ?, ?, ?, ?)""",
            (pin_hash('1234'), now(), now(), valid_until),
        )
        self.db.execute("INSERT INTO products (id, sku, name, unit_name, updated_at) VALUES (1, 'P000001', 'หมูสด', 'กก.', ?)", (now(),))
        self.db.execute("INSERT INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES ('8850000000003', 1, 'CUSTOM', 25, ?)", (now(),))
        self.db.commit()
        self.service = PosService(self.db)
        self.shift_id = self.service.open_shift(1, "TEST-01", 1, Decimal("100"))

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def test_uses_wal_and_logs_in_offline_cashier(self) -> None:
        self.assertEqual(self.db.execute("PRAGMA journal_mode").fetchone()[0], "wal")
        self.assertIsNotNone(self.service.login("POP001", "1234"))
        self.assertIsNone(self.service.login("POP001", "wrong"))

    def test_open_shift_persists_opening_cash_for_cash_drawer_reconciliation(self) -> None:
        opening_cash = self.db.execute("SELECT opening_cash FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()[0]
        self.assertEqual(opening_cash, "100.00")

        with self.assertRaisesRegex(ValueError, "เงินทอนตั้งต้นต้องไม่ติดลบ"):
            self.service.open_shift(1, "TEST-02", 1, Decimal("-1"))

    def test_cash_movements_and_close_shift_reconcile_the_local_drawer(self) -> None:
        self.service.checkout(
            document_no="T-CASH-DRAWER", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id,
            cashier_id=1, lines=[CartLine(1, Decimal("2"), Decimal("25"))],
            payment_method="cash", paid_amount=Decimal("60"), sale_uuid="cash-drawer-sale",
        )
        self.service.record_cash_movement(
            shift_id=self.shift_id, movement_type="cash_in", amount=Decimal("20"), reason="เงินทอนเพิ่ม"
        )
        self.service.record_cash_movement(
            shift_id=self.shift_id, movement_type="drop", amount=Decimal("30"), reason="นำส่งรอบแรก"
        )

        summary = self.service.shift_cash_summary(self.shift_id)
        self.assertEqual(summary.expected_cash, Decimal("140.00"))
        closed = self.service.close_shift(shift_id=self.shift_id, counted_cash=Decimal("139"), closing_note="ขาด 1 บาท")
        self.assertEqual(closed.cash_difference, Decimal("-1.00"))
        self.assertEqual(self.db.execute("SELECT status FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()[0], "closed")
        queued = self.db.execute(
            "SELECT aggregate_type FROM sync_outbox WHERE aggregate_type IN ('cash_movement', 'shift_close') ORDER BY id"
        ).fetchall()
        self.assertEqual([row[0] for row in queued], ["cash_movement", "cash_movement", "shift_close"])

    def test_effective_price_uses_a_cached_schedule_at_sale_time(self) -> None:
        self.db.execute(
            "INSERT INTO device_settings (key, value, updated_at) VALUES ('branch_id', '1', ?)",
            (now(),),
        )
        self.db.execute(
            """INSERT INTO price_versions (product_id, price, starts_at, ends_at, version, branch_id, synced_at)
               VALUES (1, '19.00', '1970-01-01T00:00:00+00:00', '2999-01-01T00:00:00+00:00', 'schedule:1', 1, ?)""",
            (now(),),
        )
        self.db.commit()
        price, version = self.service.effective_price(1, "25.00")
        self.assertEqual((price, version), (Decimal("19.00"), "schedule:1"))

    def test_offline_cashier_login_uses_server_issued_credential_and_expiry(self) -> None:
        salt = b"server-issued-salt"
        verifier = hashlib.pbkdf2_hmac("sha256", b"860531", salt, 120000, dklen=32)
        expires_at = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat()
        self.db.execute(
            """UPDATE local_cashiers
            SET pin_hash = '', cred_salt = ?, cred_verifier = ?, cred_iterations = 120000, cred_expires_at = ?, offline_valid_until = ?
            WHERE code = 'POP001'""",
            (base64.b64encode(salt).decode(), base64.b64encode(verifier).decode(), expires_at, expires_at),
        )
        self.db.commit()

        self.assertIsNotNone(self.service.login("POP001", "860531"))
        self.assertIsNone(self.service.login("POP001", "1234"))

        expired = (datetime.now(timezone.utc) - timedelta(seconds=1)).isoformat()
        self.db.execute("UPDATE local_cashiers SET cred_expires_at = ?, offline_valid_until = ? WHERE code = 'POP001'", (expired, expired))
        self.db.commit()
        self.assertIsNone(self.service.login("POP001", "860531"))

    def test_checkout_is_atomic_idempotent_and_queues_sync(self) -> None:
        line = CartLine(1, Decimal("2"), Decimal("25"), barcode="8850000000003")
        sale_id = self.service.checkout(document_no="T-0001", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id, cashier_id=1, lines=[line], payment_method="cash", paid_amount=Decimal("50"), sale_uuid="fixed-sale-uuid")
        retried_id = self.service.checkout(document_no="T-0002", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id, cashier_id=1, lines=[line], payment_method="cash", paid_amount=Decimal("50"), sale_uuid="fixed-sale-uuid")
        self.assertEqual(sale_id, retried_id)
        self.assertEqual(self.db.execute("SELECT count(*) FROM sales").fetchone()[0], 1)
        self.assertEqual(self.db.execute("SELECT count(*) FROM sale_items").fetchone()[0], 1)
        self.assertEqual(self.service.pending_sync_count(), 1)

    def test_daily_sales_summary_reads_local_sqlite_and_excludes_voids(self) -> None:
        cash_sale = self.service.checkout(
            document_no="T-DAILY-CASH", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id,
            cashier_id=1, lines=[CartLine(1, Decimal("1"), Decimal("25"))],
            payment_method="cash", paid_amount=Decimal("25"), sale_uuid="daily-cash",
        )
        void_sale = self.service.checkout(
            document_no="T-DAILY-VOID", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id,
            cashier_id=1, lines=[CartLine(1, Decimal("2"), Decimal("25"))],
            payment_method="transfer", paid_amount=Decimal("50"), sale_uuid="daily-void",
            payment_confirmed=True,
        )
        self.service.void_sale(void_sale, cashier_id=1, reason="ทดสอบยกเลิก")
        self.db.execute("UPDATE sales SET sync_status = 'synced' WHERE id = ?", (cash_sale,))
        self.db.commit()

        summary = self.service.daily_sales_summary()

        self.assertEqual(summary.transaction_count, 2)
        self.assertEqual(summary.void_count, 1)
        self.assertEqual(summary.grand_total, Decimal("25.00"))
        self.assertEqual(summary.payments, (("cash", Decimal("25.00")),))
        self.assertEqual(summary.pending_sync_count, 1)

    def test_transfer_requires_money_received_confirmation(self) -> None:
        with self.assertRaisesRegex(ValueError, "ตรวจเงินเข้า"):
            self.service.checkout(
                document_no="T-QR-NO-MONEY", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id,
                cashier_id=1, lines=[CartLine(1, Decimal("1"), Decimal("25"))],
                payment_method="transfer", paid_amount=Decimal("25"), qr_payload="PROMPTPAY",
            )
        self.assertEqual(self.db.execute("SELECT count(*) FROM sales").fetchone()[0], 0)

    def test_confirmed_transfer_snapshots_qr_without_cash_change(self) -> None:
        sale_id = self.service.checkout(
            document_no="T-QR-PAID", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id,
            cashier_id=1, lines=[CartLine(1, Decimal("1"), Decimal("25"))],
            payment_method="transfer", paid_amount=Decimal("25"), payment_reference="QR-HQ",
            qr_payload="PROMPTPAY-SNAPSHOT", payment_confirmed=True,
        )
        payment = self.db.execute("SELECT * FROM payments WHERE sale_id = ?", (sale_id,)).fetchone()
        self.assertEqual((payment["method"], payment["change_amount"]), ("transfer", "0"))
        self.assertEqual(payment["qr_payload"], "PROMPTPAY-SNAPSHOT")
        self.assertIsNotNone(payment["confirmed_at"])

    def test_underpayment_rolls_back_everything(self) -> None:
        with self.assertRaisesRegex(ValueError, "ยอดชำระไม่พอ"):
            self.service.checkout(document_no="T-UNDER", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id, cashier_id=1, lines=[CartLine(1, Decimal("1"), Decimal("25"))], payment_method="cash", paid_amount=Decimal("20"))
        self.assertEqual(self.db.execute("SELECT count(*) FROM sales").fetchone()[0], 0)
        self.assertEqual(self.service.pending_sync_count(), 0)

    def test_mock_print_marks_job_printed(self) -> None:
        sale_id = self.service.checkout(document_no="T-PRINT", branch_id=1, terminal_id="TEST-01", shift_id=self.shift_id, cashier_id=1, lines=[CartLine(1, Decimal("1"), Decimal("25"))], payment_method="cash", paid_amount=Decimal("25"))
        receipt = print_receipt(self.db, sale_id, Path(self.tmp.name) / "receipts")
        self.assertTrue(receipt.exists())
        self.assertIn("หมูสด", receipt.read_text(encoding="utf-8"))
        self.assertEqual(self.db.execute("SELECT status FROM print_jobs WHERE sale_id = ?", (sale_id,)).fetchone()[0], "printed")


if __name__ == "__main__":
    unittest.main()
