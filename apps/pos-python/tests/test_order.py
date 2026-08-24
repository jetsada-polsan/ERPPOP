"""แกนบิลแบบ Odoo — บิลซ้าย ตารางสินค้าขวา numpad แก้บรรทัดที่เลือก

ทดสอบตรงนี้เพราะเป็นที่ที่ตัวเลขเงินเกิดขึ้นจริง ผูกไว้กับปุ่มแล้วจะทดสอบไม่ได้
และความผิดพลาดจะไปโผล่ที่ยอดในบิลโดยไม่มีอะไรจับ
"""
from __future__ import annotations

import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.database import connect
from pos_python.order import (
    ALL_CATEGORIES,
    DISCOUNT,
    PRICE,
    QTY,
    Order,
    OrderLine,
    categories,
    product_grid,
)
from pos_python.services import now


def line(product_id: int = 1, name: str = "น้ำปลา", qty: str = "1", price: str = "25", **kwargs) -> OrderLine:
    return OrderLine(product_id=product_id, name=name, unit_name="ขวด",
                     qty=Decimal(qty), unit_price=Decimal(price), **kwargs)


class OrderTest(unittest.TestCase):
    def setUp(self) -> None:
        self.order = Order()

    def test_scanning_the_same_product_twice_stacks_onto_one_line(self) -> None:
        self.order.add_product(line())
        self.order.add_product(line())

        self.assertEqual(len(self.order.lines), 1)
        self.assertEqual(self.order.lines[0].qty, Decimal("2"))

    def test_the_same_product_at_a_different_price_gets_its_own_line(self) -> None:
        self.order.add_product(line())
        self.order.add_product(line(price="20"))

        self.assertEqual(len(self.order.lines), 2)

    def test_a_weighed_item_never_merges_with_another(self) -> None:
        # ป้ายเครื่องชั่งหนึ่งใบคือถุงจริงหนึ่งถุง รวมบรรทัดแล้วตรวจย้อนไม่ได้ว่ามาจากใบไหน
        self.order.add_product(line(qty="1.2", locked_qty=True, barcode_type="SCALE_WEIGHT"))
        self.order.add_product(line(qty="0.8", locked_qty=True, barcode_type="SCALE_WEIGHT"))

        self.assertEqual(len(self.order.lines), 2)

    def test_the_numpad_replaces_the_quantity_digit_by_digit(self) -> None:
        self.order.add_product(line())
        self.order.set_mode(QTY)

        for key in "12":
            self.order.press(key)

        self.assertEqual(self.order.lines[0].qty, Decimal("12"))

    def test_backspace_walks_the_number_back_to_zero(self) -> None:
        self.order.add_product(line())
        for key in "25":
            self.order.press(key)
        self.order.press("backspace")

        self.assertEqual(self.order.lines[0].qty, Decimal("2"))

        self.order.press("backspace")
        self.assertEqual(self.order.lines[0].qty, Decimal("0"))

    def test_a_decimal_point_is_accepted_once(self) -> None:
        self.order.add_product(line())
        for key in ["1", ".", "2", ".", "5"]:
            self.order.press(key)

        self.assertEqual(self.order.lines[0].qty, Decimal("1.25"))

    def test_price_mode_edits_the_price_not_the_quantity(self) -> None:
        self.order.add_product(line())
        self.order.set_mode(PRICE)
        for key in "30":
            self.order.press(key)

        self.assertEqual(self.order.lines[0].unit_price, Decimal("30"))
        self.assertEqual(self.order.lines[0].qty, Decimal("1"))

    def test_discount_mode_edits_the_discount(self) -> None:
        self.order.add_product(line(qty="4"))
        self.order.set_mode(DISCOUNT)
        for key in "20":
            self.order.press(key)

        self.assertEqual(self.order.lines[0].discount, Decimal("20"))
        self.assertEqual(self.order.lines[0].total, Decimal("80.00"))

    def test_a_weighed_line_refuses_a_quantity_change(self) -> None:
        self.order.add_product(line(qty="1.2", locked_qty=True))
        self.order.set_mode(QTY)
        self.order.press("9")

        self.assertEqual(self.order.lines[0].qty, Decimal("1.2"),
                         "น้ำหนักมาจากป้ายที่พิมพ์แล้ว แก้ที่เครื่องไม่ได้")

    def test_switching_mode_starts_a_fresh_number(self) -> None:
        self.order.add_product(line())
        self.order.press("5")
        self.order.set_mode(PRICE)
        self.order.press("9")

        self.assertEqual(self.order.lines[0].qty, Decimal("5"))
        self.assertEqual(self.order.lines[0].unit_price, Decimal("9"))

    def test_the_numpad_does_nothing_with_no_line_selected(self) -> None:
        self.order.press("5")   # ต้องไม่ระเบิด

        self.assertEqual(self.order.lines, [])

    def test_sign_toggle_turns_a_line_into_a_return(self) -> None:
        self.order.add_product(line(qty="2"))
        self.order.press("3")
        self.order.press("+/-")

        self.assertEqual(self.order.lines[0].qty, Decimal("-3"))

    def test_removing_the_selected_line_moves_the_selection(self) -> None:
        self.order.add_product(line(product_id=1))
        self.order.add_product(line(product_id=2, name="ข้าวสาร"))
        self.order.remove_selected()

        self.assertEqual(len(self.order.lines), 1)
        self.assertEqual(self.order.selected.product_id, 1)

    def test_removing_the_last_line_leaves_nothing_selected(self) -> None:
        self.order.add_product(line())
        self.order.remove_selected()

        self.assertIsNone(self.order.selected)

    def test_the_totals_add_up_with_vat_taken_out_of_the_price(self) -> None:
        self.order.add_product(line(price="107", is_vat=True))
        self.order.add_product(line(product_id=2, name="ผักสด", price="100", is_vat=False))

        self.assertEqual(self.order.subtotal(), Decimal("207.00"))
        self.assertEqual(self.order.grand_total(), Decimal("207.00"))
        self.assertEqual(self.order.vat_total(Decimal("7")), Decimal("7.00"),
                         "สินค้ายกเว้น VAT ต้องไม่ถูกนับเข้าฐานภาษี")

    def test_change_is_what_goes_back_to_the_customer(self) -> None:
        self.order.add_product(line(price="389.80"))

        self.assertEqual(self.order.change_for(Decimal("400")), Decimal("10.20"))
        self.assertEqual(self.order.change_for(Decimal("389.80")), Decimal("0.00"))
        self.assertEqual(self.order.change_for(Decimal("100")), Decimal("0.00"), "จ่ายไม่พอไม่ใช่เงินทอนติดลบ")

    def test_the_order_converts_to_the_lines_checkout_expects(self) -> None:
        self.order.add_product(line(qty="2", price="25", barcode="X1", barcode_type="INTERNAL_13"))

        cart = self.order.to_cart_lines()

        self.assertEqual(len(cart), 1)
        self.assertEqual(cart[0].qty, Decimal("2"))
        self.assertEqual(cart[0].barcode_type, "INTERNAL_13")


class ProductGridTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        for product_id, sku, name, category, active in [
            (1, "P1", "หมูสามชั้น", "ของสด", 1),
            (2, "P2", "น้ำจิ้มสุกี้", "เครื่องปรุง", 1),
            (3, "P3", "ผักรวม", "ของสด", 1),
            (4, "P4", "ของเลิกขาย", "ของสด", 0),
            (5, "P5", "สินค้าไม่มีหมวด", None, 1),
        ]:
            self.db.execute(
                """INSERT INTO products (id, sku, name, unit_name, category_name, active, price, updated_at)
                VALUES (?, ?, ?, 'ชิ้น', ?, ?, '50', ?)""",
                (product_id, sku, name, category, active, now()),
            )
        self.db.commit()

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def test_only_categories_that_have_stock_on_sale_are_offered(self) -> None:
        found = categories(self.db)

        self.assertEqual(found[0], ALL_CATEGORIES)
        self.assertIn("ของสด", found)
        self.assertIn("เครื่องปรุง", found)

    def test_the_grid_hides_products_that_are_no_longer_sold(self) -> None:
        names = [row["name"] for row in product_grid(self.db)]

        self.assertNotIn("ของเลิกขาย", names)

    def test_picking_a_category_narrows_the_grid(self) -> None:
        names = [row["name"] for row in product_grid(self.db, category="ของสด")]

        self.assertEqual(sorted(names), ["ผักรวม", "หมูสามชั้น"])

    def test_search_matches_both_name_and_code(self) -> None:
        self.assertEqual([row["name"] for row in product_grid(self.db, search="น้ำจิ้ม")], ["น้ำจิ้มสุกี้"])
        self.assertEqual([row["name"] for row in product_grid(self.db, search="P3")], ["ผักรวม"])

    def test_search_and_category_apply_together(self) -> None:
        self.assertEqual([row["name"] for row in product_grid(self.db, category="ของสด", search="ผัก")], ["ผักรวม"])


if __name__ == "__main__":
    unittest.main()
