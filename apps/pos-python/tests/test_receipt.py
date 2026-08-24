"""ใบเสร็จ 80mm — สิ่งเดียวที่ลูกค้าถือกลับบ้าน

ตรวจด้วยตาทีละใบไม่ไหว และพรีวิวบนจอกับของที่พิมพ์จริงต้องมาจากตัวเดียวกัน
"""
from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.barcode import ean13_check_digit, replace_scale_profiles, scale_cart_line
from pos_python.database import connect
from pos_python.receipt import columns, display_width, render, render_text, width_for
from pos_python.services import CartLine, PosService, now, pin_hash

COMPANY = {
    "name": "บริษัท ป๊อบสตาร์ฟู้ดเทรดดิ้ง จำกัด",
    "branch": "สำนักงานใหญ่",
    "phone": "045-000-000",
    "tax_id": "0345560000000",
}


class ReceiptLayoutTest(unittest.TestCase):
    def test_thai_vowel_marks_do_not_count_as_width(self) -> None:
        # "ผักรวมสด" มีสระอะเหนือ ก ถ้านับเป็นความกว้างด้วย คอลัมน์ราคาจะเยื้อง
        self.assertEqual(display_width("ผัก"), 2)
        self.assertEqual(display_width("น้ำ"), 2)
        self.assertEqual(display_width("abc"), 3)

    def test_the_money_column_lines_up_whatever_the_name(self) -> None:
        width = 42
        rows = [
            columns("หมูสามชั้น 1.2 กก.", "226.80", width),
            columns("น้ำจิ้มหมูกระทะ x1", "45.00", width),
            columns("ผักรวมสด x2", "118.00", width),
        ]

        for row in rows:
            self.assertEqual(display_width(row), width, f"แถวนี้กว้างไม่เท่ากัน: {row}")
            self.assertTrue(row.rstrip().endswith(("226.80", "45.00", "118.00")))

    def test_a_name_too_long_is_cut_so_the_price_survives(self) -> None:
        row = columns("สินค้าชื่อยาวมากจนเกินความกว้างของกระดาษใบเสร็จแน่นอน", "1,234.00", 32)

        self.assertEqual(display_width(row), 32)
        self.assertTrue(row.endswith("1,234.00"), "ตัวเงินต้องอ่านออกเสมอ ตัดชื่อแทน")

    def test_paper_width_decides_the_character_count(self) -> None:
        self.assertEqual(width_for(80), 42)
        self.assertEqual(width_for(58), 32)
        self.assertEqual(width_for(None), 42)


class ReceiptContentTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.db.execute(
            "INSERT INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (1, 'POP001', 'แคชเชียร์', ?, ?)",
            (pin_hash("1234"), now()),
        )
        for product_id, sku, name, unit, price, is_vat in [
            (1, "800123", "หมูสามชั้น", "กก.", "189", 1),
            (2, "P1203", "น้ำจิ้มหมูกระทะ", "ขวด", "45", 1),
            (3, "P0775", "ผักรวมสด", "ถุง", "59", 0),
        ]:
            self.db.execute(
                "INSERT INTO products (id, sku, name, unit_name, price, is_vat, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
                (product_id, sku, name, unit, price, is_vat, now()),
            )
        self.db.execute(
            "INSERT INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES ('800123', 1, 'SCALE_WEIGHT', 189, ?)",
            (now(),),
        )
        replace_scale_profiles(self.db, [{
            "code": "POPSTAR-800", "prefix": "800", "plu_length": 6, "value_length": 6,
            "value_type": "price", "check_digit": "ean13", "total_length": 13,
        }])
        self.db.commit()
        self.pos = PosService(self.db)
        self.shift_id = self.pos.open_shift(1, "POS-01", 1, Decimal("0"))

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def sell(self, document_no: str = "PS-HQ-000001") -> int:
        body = "800123" + "022680"
        weighed = scale_cart_line(self.db, body + str(ean13_check_digit(body)))
        return self.pos.checkout(
            document_no=document_no, branch_id=1, terminal_id="POS-01", shift_id=self.shift_id, cashier_id=1,
            lines=[
                weighed,
                CartLine(product_id=2, qty=Decimal("1"), unit_price=Decimal("45")),
                CartLine(product_id=3, qty=Decimal("2"), unit_price=Decimal("59")),
            ],
            payment_method="cash", paid_amount=Decimal("400"),
        )

    def test_the_receipt_reads_like_the_one_handed_over_the_counter(self) -> None:
        text = render_text(self.db, self.sell(), company=COMPANY)

        self.assertIn("บริษัท ป๊อบสตาร์ฟู้ดเทรดดิ้ง จำกัด", text)
        self.assertIn("ใบเสร็จรับเงิน / ใบกำกับภาษีอย่างย่อ", text)
        self.assertIn("PS-HQ-000001", text)
        self.assertIn("POP001", text)
        self.assertIn("ขอบคุณที่ใช้บริการ", text)

    def test_a_short_tax_invoice_states_the_vat_amount(self) -> None:
        text = render_text(self.db, self.sell(), company=COMPANY)

        # ใบกำกับภาษีอย่างย่อต้องแสดงจำนวนภาษี ไม่ใช่แค่ยอดรวม
        self.assertIn("ภาษีมูลค่าเพิ่ม", text)
        self.assertIn("มูลค่าก่อน VAT", text)
        self.assertIn("เลขประจำตัวผู้เสียภาษี", text)

    def test_cash_taken_and_change_given_both_appear(self) -> None:
        text = render_text(self.db, self.sell(), company=COMPANY)

        self.assertIn("เงินสดรับ", text)
        self.assertIn("400.00", text)
        self.assertIn("เงินทอน", text)
        self.assertIn("10.20", text)

    def test_a_weighed_line_shows_its_weight_not_a_piece_count(self) -> None:
        lines = render(self.db, self.sell(), company=COMPANY)
        weighed = next(line for line in lines if "หมูสามชั้น" in line)

        self.assertIn("1.2 กก.", weighed)
        self.assertNotIn("x1", weighed)

    def test_a_voided_bill_says_so_on_its_face(self) -> None:
        sale_id = self.sell()
        self.pos.void_sale(sale_id, cashier_id=1, reason="ลูกค้าคืนของ")

        text = render_text(self.db, sale_id, company=COMPANY)

        self.assertIn("บิลนี้ถูกยกเลิก", text)
        self.assertIn("ลูกค้าคืนของ", text)

    def test_every_line_fits_the_paper(self) -> None:
        sale_id = self.sell()
        for paper, expected in [(80, 42), (58, 32)]:
            for line in render(self.db, sale_id, company=COMPANY, paper_width_mm=paper):
                self.assertLessEqual(display_width(line), expected,
                                     f"บรรทัดล้นกระดาษ {paper}mm: {line}")


if __name__ == "__main__":
    unittest.main()
