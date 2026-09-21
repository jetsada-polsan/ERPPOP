from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.barcode import ean13_check_digit, replace_scale_profiles
from pos_python.database import connect
from pos_python.hardware_uat import inspect_scan
from pos_python.services import now


def label(plu: str, total_price_satang: str) -> str:
    body = plu + total_price_satang
    return body + str(ean13_check_digit(body))


class HardwareContractTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        timestamp = now()
        self.db.execute(
            "INSERT INTO products (id, sku, name, unit_name, price, updated_at) VALUES (1, 'P-NORMAL', 'น้ำดื่ม', 'ขวด', '10', ?)",
            (timestamp,),
        )
        self.db.execute(
            "INSERT INTO products (id, sku, name, unit_name, price, updated_at) VALUES (2, '801001', 'หมูชั่ง', 'กก.', '200', ?)",
            (timestamp,),
        )
        self.db.execute(
            "INSERT INTO products (id, sku, name, unit_name, price, updated_at) VALUES (3, '800001', 'ไก่ชั่ง', 'กก.', '200', ?)",
            (timestamp,),
        )
        self.db.execute(
            "INSERT INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES ('8850000000003', 1, 'CUSTOM', '10', ?)",
            (timestamp,),
        )
        self.db.execute(
            "INSERT INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES ('801001', 2, 'SCALE_PLU', '200', ?)",
            (timestamp,),
        )
        self.db.execute(
            "INSERT INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES ('800001', 3, 'SCALE_PLU', '200', ?)",
            (timestamp,),
        )
        replace_scale_profiles(self.db, [
            {"code": "POPSTAR-800", "prefix": "800", "plu_length": 6, "value_length": 6,
             "value_type": "price", "check_digit": "ean13", "total_length": 13},
            {"code": "POPSTAR-801", "prefix": "801", "plu_length": 6, "value_length": 6,
             "value_type": "price", "check_digit": "ean13", "total_length": 13},
        ])
        self.db.commit()

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def test_keyboard_wedge_normal_barcode_is_one_unit(self) -> None:
        result = inspect_scan(self.db, "8850000000003")
        self.assertEqual((result.kind, result.name, result.qty, result.unit_price), ("normal", "น้ำดื่ม", 1, 10))

    def test_800_and_801_labels_are_scale_scans(self) -> None:
        for prefix in ("800", "801"):
            # PLU is six digits including the configured prefix; price is 125.50.
            scanned = label(prefix + "001", "012550")
            result = inspect_scan(self.db, scanned)
            self.assertEqual(result.kind, "scale")
            self.assertEqual(result.qty, Decimal("0.6275"))


if __name__ == "__main__":
    unittest.main()
