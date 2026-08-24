from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.barcode import decode_scale_label, ean13_check_digit, replace_scale_profiles, scale_cart_line
from pos_python.database import connect
from pos_python.services import PosService, now, pin_hash


def label(plu: str, total_price_satang: str) -> str:
    twelve = plu + total_price_satang
    return twelve + str(ean13_check_digit(twelve))


class ScaleSaleUatTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.db.execute("INSERT INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (1, 'POP001', 'Tester', ?, ?)", (pin_hash('1234'), now()))
        self.db.execute("INSERT INTO products (id, sku, name, unit_name, updated_at) VALUES (1, '800123', 'หมูสามชั้นสไลซ์', 'กก.', ?)", (now(),))
        self.db.execute("INSERT INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES ('800123', 1, 'SCALE_WEIGHT', 200, ?)", (now(),))
        # เครื่องต้องได้รูปแบบป้ายมาจาก ERP ก่อน ไม่ใช่เดาเองจากตัวเลข
        replace_scale_profiles(self.db, [
            {"code": "POPSTAR-800", "prefix": "800", "plu_length": 6, "value_length": 6,
             "value_type": "price", "check_digit": "ean13", "total_length": 13},
            {"code": "POPSTAR-801", "prefix": "801", "plu_length": 6, "value_length": 6,
             "value_type": "price", "check_digit": "ean13", "total_length": 13},
        ])
        self.db.commit()
        self.pos = PosService(self.db)
        self.shift_id = self.pos.open_shift(1, "TEST-SCALE", 1, Decimal("0"))

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def test_scale_label_creates_exact_quantity_and_sale_snapshot(self) -> None:
        scanned = label("800123", "012550")  # price embedded: 125.50
        line = scale_cart_line(self.db, scanned)
        self.assertEqual(line.qty, Decimal("0.6275"))
        self.assertEqual(line.unit_price, Decimal("200"))
        sale_id = self.pos.checkout(document_no="SCALE-0001", branch_id=1, terminal_id="TEST-SCALE", shift_id=self.shift_id, cashier_id=1, lines=[line], payment_method="cash", paid_amount=Decimal("125.50"))
        item = self.db.execute("SELECT qty, unit_price, line_total, source_barcode, price_version FROM sale_items WHERE sale_id = ?", (sale_id,)).fetchone()
        self.assertEqual((Decimal(item["qty"]), Decimal(item["unit_price"]), Decimal(item["line_total"])), (Decimal("0.6275"), Decimal("200"), Decimal("125.50")))
        self.assertEqual(item["source_barcode"], scanned)
        self.assertEqual(item["price_version"], "scale-label")
        self.assertEqual(self.pos.pending_sync_count(), 1)

    def test_rejects_tampered_scale_label_before_sale(self) -> None:
        valid = label("800123", "012550")
        with self.assertRaisesRegex(ValueError, "check digit"):
            scale_cart_line(self.db, "800124" + valid[6:])
        self.assertEqual(self.db.execute("SELECT count(*) FROM sales").fetchone()[0], 0)

    def test_reports_unknown_scale_plu(self) -> None:
        with self.assertRaisesRegex(ValueError, "800999"):
            scale_cart_line(self.db, label("800999", "010000"))


if __name__ == "__main__":
    unittest.main()
