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
        self.db.execute("INSERT INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (1, 'POP001', 'Tester', ?, ?)", (pin_hash('1234'), now()))
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

    def test_offline_cashier_login_uses_server_issued_credential_and_expiry(self) -> None:
        salt = b"server-issued-salt"
        verifier = hashlib.pbkdf2_hmac("sha256", b"860531", salt, 120000, dklen=32)
        expires_at = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat()
        self.db.execute(
            """UPDATE local_cashiers
            SET pin_hash = '', cred_salt = ?, cred_verifier = ?, cred_iterations = 120000, cred_expires_at = ?
            WHERE code = 'POP001'""",
            (base64.b64encode(salt).decode(), base64.b64encode(verifier).decode(), expires_at),
        )
        self.db.commit()

        self.assertIsNotNone(self.service.login("POP001", "860531"))
        self.assertIsNone(self.service.login("POP001", "1234"))

        expired = (datetime.now(timezone.utc) - timedelta(seconds=1)).isoformat()
        self.db.execute("UPDATE local_cashiers SET cred_expires_at = ? WHERE code = 'POP001'", (expired,))
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
