"""ขายให้ครบทุกเงื่อนไขที่เกิดขึ้นจริงหน้าเคาน์เตอร์

ไม่ได้ถามว่า "ขายได้ไหม" แต่ถามว่าเงื่อนไขที่ควรปฏิเสธถูกปฏิเสธจริงไหม
และเงื่อนไขที่ควรผ่านให้ตัวเลขถูกต้องไหม — บิลที่คิดเงินผิดจะดูปกติทุกอย่าง
จนกว่าจะปิดกะแล้วเงินไม่ตรง
"""
from __future__ import annotations

import sqlite3
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.barcode import ean13_check_digit, replace_scale_profiles, scale_cart_line
from pos_python.database import connect
from pos_python.services import CartLine, PosService, now, pin_hash


def label(plu: str, value: str) -> str:
    twelve = plu + value
    return twelve + str(ean13_check_digit(twelve))


class SaleConditionsTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.db.execute(
            "INSERT INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (1, 'POP001', 'แคชเชียร์', ?, ?)",
            (pin_hash("1234"), now()),
        )
        # สินค้าครบทุกประเภทบาร์โค้ดที่ระบบรองรับ
        for product_id, sku, name in [
            (1, "P000001", "น้ำปลาขวด"),
            (2, "P000002", "ข้าวสารถุง"),
            (3, "800123", "หมูสามชั้นสไลซ์"),
            (4, "P000004", "สินค้าเลิกขาย"),
        ]:
            self.db.execute(
                "INSERT INTO products (id, sku, name, unit_name, updated_at) VALUES (?, ?, ?, 'ชิ้น', ?)",
                (product_id, sku, name, now()),
            )
        self.db.execute("UPDATE products SET active = 0 WHERE id = 4")
        for barcode, product_id, barcode_type, price in [
            ("8850000000003", 1, "EAN13_STANDARD", 25),
            ("2990000000017", 2, "INTERNAL_13", 180),
            ("ABC-XYZ/01", 2, "CUSTOM", 180),
            ("800123", 3, "SCALE_WEIGHT", 200),
        ]:
            self.db.execute(
                "INSERT INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES (?, ?, ?, ?, ?)",
                (barcode, product_id, barcode_type, price, now()),
            )
        replace_scale_profiles(self.db, [{
            "code": "POPSTAR-800", "prefix": "800", "plu_length": 6, "value_length": 6,
            "value_type": "price", "check_digit": "ean13", "total_length": 13,
        }])
        self.db.commit()
        self.pos = PosService(self.db)
        self.shift_id = self.pos.open_shift(1, "TILL-1", 1, Decimal("1000"))

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def line(self, product_id: int, qty: str, price: str, barcode: str, barcode_type: str, discount: str = "0") -> CartLine:
        return CartLine(
            product_id=product_id, qty=Decimal(qty), unit_price=Decimal(price),
            discount=Decimal(discount), barcode=barcode, source_barcode=barcode, barcode_type=barcode_type,
        )

    def sell(self, document_no: str, lines: list[CartLine], paid: str, method: str = "cash") -> int:
        return self.pos.checkout(
            document_no=document_no, branch_id=1, terminal_id="TILL-1", shift_id=self.shift_id,
            cashier_id=1, lines=lines, payment_method=method, paid_amount=Decimal(paid),
        )

    # ---------- เงื่อนไขที่ต้องขายได้ ----------

    def test_each_barcode_type_finds_its_product(self) -> None:
        for barcode, expected_type in [
            ("8850000000003", "EAN13_STANDARD"),
            ("2990000000017", "INTERNAL_13"),
            ("ABC-XYZ/01", "CUSTOM"),
        ]:
            found = self.pos.lookup_barcode(barcode)
            self.assertIsNotNone(found, f"สแกน {barcode} ต้องเจอสินค้า")
            self.assertEqual(found["barcode_type"], expected_type)

    def test_a_multi_line_bill_adds_up(self) -> None:
        sale_id = self.sell("B-001", [
            self.line(1, "3", "25", "8850000000003", "EAN13_STANDARD"),
            self.line(2, "2", "180", "2990000000017", "INTERNAL_13"),
        ], "500")

        sale = self.db.execute("SELECT subtotal, grand_total FROM sales WHERE id = ?", (sale_id,)).fetchone()
        self.assertEqual(Decimal(sale["subtotal"]), Decimal("435.00"))   # 75 + 360
        self.assertEqual(Decimal(sale["grand_total"]), Decimal("435.00"))
        self.assertEqual(self.db.execute("SELECT count(*) FROM sale_items WHERE sale_id = ?", (sale_id,)).fetchone()[0], 2)

    def test_a_line_discount_comes_off_the_total(self) -> None:
        sale_id = self.sell("B-002", [self.line(1, "4", "25", "8850000000003", "EAN13_STANDARD", discount="20")], "100")

        sale = self.db.execute("SELECT subtotal, discount_total, grand_total FROM sales WHERE id = ?", (sale_id,)).fetchone()
        self.assertEqual(Decimal(sale["subtotal"]), Decimal("100.00"))
        self.assertEqual(Decimal(sale["discount_total"]), Decimal("20.00"))
        self.assertEqual(Decimal(sale["grand_total"]), Decimal("80.00"))

    def test_a_scale_label_sells_by_the_price_printed_on_it(self) -> None:
        line = scale_cart_line(self.db, label("800123", "012550"))
        sale_id = self.sell("B-003", [line], "125.50")

        item = self.db.execute("SELECT qty, unit_price, line_total, source_barcode FROM sale_items WHERE sale_id = ?", (sale_id,)).fetchone()
        self.assertEqual(Decimal(item["line_total"]), Decimal("125.50"))
        self.assertEqual(Decimal(item["unit_price"]), Decimal("200"))
        self.assertEqual(item["source_barcode"], label("800123", "012550"), "ต้องเก็บป้ายที่สแกนจริงไว้ให้ตรวจย้อนได้")

    def test_the_bill_keeps_the_name_it_was_sold_under(self) -> None:
        sale_id = self.sell("B-004", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")
        self.db.execute("UPDATE products SET name = 'ชื่อใหม่หลังขาย' WHERE id = 1")
        self.db.commit()

        item = self.db.execute("SELECT product_name_snapshot FROM sale_items WHERE sale_id = ?", (sale_id,)).fetchone()
        self.assertEqual(item["product_name_snapshot"], "น้ำปลาขวด",
                         "บิลที่ออกไปแล้วต้องไม่เปลี่ยนตามแฟ้มสินค้า")

    def test_every_sale_lands_in_the_outbox(self) -> None:
        before = self.pos.pending_sync_count()
        self.sell("B-005", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")

        self.assertEqual(self.pos.pending_sync_count(), before + 1)

    # ---------- เงื่อนไขที่ต้องถูกปฏิเสธ ----------

    def test_an_empty_cart_is_refused(self) -> None:
        with self.assertRaises(ValueError):
            self.sell("B-006", [], "0")

    def test_underpayment_is_refused_and_leaves_nothing_behind(self) -> None:
        with self.assertRaises(ValueError):
            self.sell("B-007", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "20")

        self.assertIsNone(self.db.execute("SELECT id FROM sales WHERE document_no = 'B-007'").fetchone())

    def test_selling_a_discontinued_product_rolls_the_whole_bill_back(self) -> None:
        with self.assertRaises(ValueError):
            self.sell("B-008", [
                self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD"),
                self.line(4, "1", "50", "P000004", "CUSTOM"),
            ], "75")

        # บรรทัดแรกถูกต้อง แต่ทั้งบิลต้องไม่เหลือร่องรอย ไม่ใช่ขายไปครึ่งบิล
        self.assertIsNone(self.db.execute("SELECT id FROM sales WHERE document_no = 'B-008'").fetchone())
        self.assertEqual(self.db.execute("SELECT count(*) FROM sale_items").fetchone()[0], 0)

    def test_an_unknown_barcode_finds_nothing(self) -> None:
        self.assertIsNone(self.pos.lookup_barcode("9999999999999"))

    def test_the_same_document_number_cannot_be_issued_twice(self) -> None:
        self.sell("B-009", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")

        with self.assertRaises(sqlite3.IntegrityError):
            self.sell("B-009", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")

    def test_replaying_the_same_sale_uuid_returns_the_original_bill(self) -> None:
        first = self.pos.checkout(
            document_no="B-010", branch_id=1, terminal_id="TILL-1", shift_id=self.shift_id, cashier_id=1,
            lines=[self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")],
            payment_method="cash", paid_amount=Decimal("25"), sale_uuid="fixed-uuid",
        )
        second = self.pos.checkout(
            document_no="B-010-again", branch_id=1, terminal_id="TILL-1", shift_id=self.shift_id, cashier_id=1,
            lines=[self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")],
            payment_method="cash", paid_amount=Decimal("25"), sale_uuid="fixed-uuid",
        )

        self.assertEqual(first, second, "ส่งซ้ำต้องได้บิลเดิม ไม่ใช่บิลใหม่")
        self.assertEqual(self.db.execute("SELECT count(*) FROM sales").fetchone()[0], 1)


    # ---------- เงื่อนไขที่เพิ่งปิดช่องโหว่ ----------

    def test_selling_into_a_closed_shift_is_refused(self) -> None:
        self.db.execute("UPDATE shifts SET status = 'closed', closed_at = ? WHERE id = ?", (now(), self.shift_id))
        self.db.commit()

        with self.assertRaises(ValueError) as raised:
            self.sell("B-011", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")

        # ยอดขายที่ตกเข้ากะที่นับเงินจบแล้ว ทำให้เงินในลิ้นชักไม่ตรงโดยไม่รู้ว่าเริ่มเพี้ยนตรงไหน
        self.assertIn("กะนี้ปิดแล้ว", str(raised.exception))
        self.assertIsNone(self.db.execute("SELECT id FROM sales WHERE document_no = 'B-011'").fetchone())

    def test_selling_into_a_shift_that_does_not_exist_is_refused(self) -> None:
        with self.assertRaises(ValueError):
            self.pos.checkout(
                document_no="B-012", branch_id=1, terminal_id="TILL-1", shift_id=9999, cashier_id=1,
                lines=[self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")],
                payment_method="cash", paid_amount=Decimal("25"),
            )

    def test_change_given_is_recorded_so_the_drawer_reconciles(self) -> None:
        sale_id = self.sell("B-013", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "500")

        payment = self.db.execute("SELECT amount, change_amount FROM payments WHERE sale_id = ?", (sale_id,)).fetchone()
        self.assertEqual(Decimal(payment["amount"]), Decimal("500.00"))
        self.assertEqual(Decimal(payment["change_amount"]), Decimal("475.00"),
                         "ไม่เก็บเงินทอน แล้วยอดเงินสดที่ควรมีในลิ้นชักจะเกินจริงเท่ากับเงินทอน")

    def test_paying_the_exact_amount_leaves_no_change(self) -> None:
        sale_id = self.sell("B-014", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")

        self.assertEqual(
            Decimal(self.db.execute("SELECT change_amount FROM payments WHERE sale_id = ?", (sale_id,)).fetchone()["change_amount"]),
            Decimal("0.00"),
        )

    def test_voiding_a_bill_keeps_it_and_records_who_and_why(self) -> None:
        sale_id = self.sell("B-015", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")

        self.pos.void_sale(sale_id, cashier_id=1, reason="ลูกค้าเปลี่ยนใจ")

        sale = self.db.execute("SELECT is_void, void_reason, voided_by, voided_at FROM sales WHERE id = ?", (sale_id,)).fetchone()
        self.assertEqual(sale["is_void"], 1)
        self.assertEqual(sale["void_reason"], "ลูกค้าเปลี่ยนใจ")
        self.assertEqual(sale["voided_by"], 1)
        self.assertIsNotNone(sale["voided_at"])
        # บิลยังอยู่ ไม่ถูกลบ — ต้องตรวจย้อนได้เสมอ
        self.assertEqual(self.db.execute("SELECT count(*) FROM sale_items WHERE sale_id = ?", (sale_id,)).fetchone()[0], 1)

    def test_a_void_without_a_reason_is_refused(self) -> None:
        sale_id = self.sell("B-016", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")

        with self.assertRaises(ValueError):
            self.pos.void_sale(sale_id, cashier_id=1, reason="   ")

        self.assertEqual(self.db.execute("SELECT is_void FROM sales WHERE id = ?", (sale_id,)).fetchone()["is_void"], 0)

    def test_a_bill_cannot_be_voided_twice(self) -> None:
        sale_id = self.sell("B-017", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")
        self.pos.void_sale(sale_id, cashier_id=1, reason="สแกนผิด")

        with self.assertRaises(ValueError):
            self.pos.void_sale(sale_id, cashier_id=1, reason="ยกเลิกซ้ำ")

    def test_a_void_can_be_queued_alongside_the_sale_it_cancels(self) -> None:
        # คิวคุมด้วย aggregate_uuid ที่เป็น unique — ถ้าการยกเลิกใช้คีย์เดียวกับบิล
        # มันจะ insert ไม่ได้ แล้วการยกเลิกจะไม่เคยถูกส่งขึ้น ERP เลย
        sale_id = self.sell("B-019", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")
        sale_uuid = self.db.execute("SELECT sale_uuid FROM sales WHERE id = ?", (sale_id,)).fetchone()["sale_uuid"]

        self.pos.void_sale(sale_id, cashier_id=1, reason="ยกเลิกทันที")

        queued = {row["aggregate_uuid"] for row in self.db.execute("SELECT aggregate_uuid FROM sync_outbox")}
        self.assertIn(sale_uuid, queued)
        self.assertIn(f"{sale_uuid}:void", queued)

    def test_the_void_is_queued_for_the_server_too(self) -> None:
        sale_id = self.sell("B-018", [self.line(1, "1", "25", "8850000000003", "EAN13_STANDARD")], "25")
        before = self.pos.pending_sync_count()

        self.pos.void_sale(sale_id, cashier_id=1, reason="ยกเลิกหลังพิมพ์")

        self.assertEqual(self.pos.pending_sync_count(), before + 1,
                         "ยกเลิกแค่ในเครื่องแปลว่า ERP ยังนับบิลนั้นเป็นยอดขายอยู่")
        queued = self.db.execute("SELECT aggregate_type FROM sync_outbox ORDER BY id DESC LIMIT 1").fetchone()
        self.assertEqual(queued["aggregate_type"], "sale_void")


if __name__ == "__main__":
    unittest.main()
