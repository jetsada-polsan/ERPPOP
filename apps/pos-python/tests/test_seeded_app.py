"""เครื่องที่เพิ่งติดตั้งต้องขายได้ทันที

อาการ "build แล้วใช้งานไม่ได้" ส่วนหนึ่งมาจากเปิดมาแล้วไม่มีสินค้าให้กด
และยิงป้ายเครื่องชั่งไม่ติดเพราะยังไม่มีรูปแบบป้าย
"""
from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

import main
from pos_python.barcode import ean13_check_digit, load_scale_profiles, scale_cart_line
from pos_python.database import connect
from pos_python.order import ALL_CATEGORIES, categories, product_grid
from pos_python.services import PosService


class SeededAppTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        main.seed(self.db)
        self.service = PosService(self.db)

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def test_the_grid_has_products_to_press_on_first_launch(self) -> None:
        grid = product_grid(self.db)

        self.assertGreaterEqual(len(grid), 6, "เปิดมาแล้วต้องมีของให้กดขาย ไม่ใช่หน้าว่าง")
        for row in grid:
            self.assertGreater(Decimal(str(row["price"])), 0, f"{row['name']} ต้องมีราคา ไม่งั้นกดแล้วขายไม่ได้")

    def test_the_category_pills_have_something_behind_each_one(self) -> None:
        found = categories(self.db)

        self.assertEqual(found[0], ALL_CATEGORIES)
        self.assertGreaterEqual(len(found), 3)
        for name in found[1:]:
            self.assertGreater(len(product_grid(self.db, category=name)), 0, f"หมวด {name} ต้องไม่ว่าง")

    def test_a_scale_label_works_out_of_the_box(self) -> None:
        self.assertGreater(len(load_scale_profiles(self.db)), 0, "เครื่องใหม่ต้องมีรูปแบบป้ายติดมาด้วย")

        body = "801001" + "012550"
        line = scale_cart_line(self.db, body + str(ean13_check_digit(body)))

        self.assertEqual(line.barcode_type, "SCALE_WEIGHT")
        self.assertEqual(line.unit_price, Decimal("189.00"))

    def test_each_barcode_type_in_the_seed_scans(self) -> None:
        for barcode, expected in [
            ("801001", "SCALE_PLU"),
            ("8850000000003", "EAN13_STANDARD"),
            ("2990000000017", "INTERNAL_13"),
            ("ICE-01", "CUSTOM"),
        ]:
            found = self.service.lookup_barcode(barcode)
            self.assertIsNotNone(found, f"ยิง {barcode} ต้องเจอสินค้า")
            self.assertEqual(found["barcode_type"], expected)

    def test_the_seed_includes_a_vat_exempt_item(self) -> None:
        exempt = [row for row in product_grid(self.db) if not row["is_vat"]]

        self.assertGreater(len(exempt), 0, "ต้องมีของยกเว้น VAT ไว้ทดสอบการแยกยอดภาษี")

    def test_seeding_twice_does_not_duplicate_the_catalogue(self) -> None:
        before = len(product_grid(self.db))
        main.seed(self.db)

        self.assertEqual(len(product_grid(self.db)), before, "เปิดโปรแกรมซ้ำต้องไม่ได้สินค้าเพิ่มมาเอง")


    def test_a_fresh_till_prints_a_receipt_with_a_company_header(self) -> None:
        from decimal import Decimal as D

        from pos_python.mock_printer import receipt_for
        from pos_python.services import CartLine

        shift = self.service.open_shift(1, "PY-TEST-01", 1, D("0"))
        sale_id = self.service.checkout(
            document_no="SEED-0001", branch_id=1, terminal_id="PY-TEST-01", shift_id=shift, cashier_id=1,
            lines=[CartLine(product_id=3, qty=D("1"), unit_price=D("45"))],
            payment_method="cash", paid_amount=D("100"),
        )

        text = receipt_for(self.db, sale_id)

        # หัวใบเสร็จว่างเปล่าคือใบเสร็จที่ให้ลูกค้าไม่ได้
        self.assertIn("ป๊อบสตาร์", text)
        self.assertIn("ใบเสร็จรับเงิน", text)
        self.assertIn("ภาษีมูลค่าเพิ่ม", text)
        self.assertIn("เงินทอน", text)
        self.assertIn("ขอบคุณที่ใช้บริการ", text)


if __name__ == "__main__":
    unittest.main()
