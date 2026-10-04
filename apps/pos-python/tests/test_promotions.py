"""โปรซื้อครบจำนวนต้องคิดตรงกับ ERP ทุกบาท

ERP หักโปรเองทุกบิล ถ้าเครื่องคิดต่างแม้สตางค์เดียวเกิน 0.02 บิลถูกปฏิเสธทั้งที่เก็บเงินไปแล้ว
ตัวเลขในเทสต์เดียวกับเทสต์ฝั่ง ERP (tests/Feature/PosPricingGuardTest.php)
"""
from __future__ import annotations

import tempfile
import unittest
from datetime import date
from decimal import Decimal
from pathlib import Path

from pos_python.database import connect
from pos_python.order import Order, OrderLine
from pos_python.promotions import QtyPromotion, active_promotions, promotion_discounts
from pos_python.services import CartLine, PosService, now, pin_hash
from pos_python.sync_service import SyncService


def line(product_id: int, qty: str, price: str) -> OrderLine:
    return OrderLine(product_id=product_id, name="x", unit_name="ชิ้น", qty=Decimal(qty), unit_price=Decimal(price))


class PromotionMathTest(unittest.TestCase):
    def test_bundle_price_counts_complete_sets_and_leaves_the_rest_at_full_price(self) -> None:
        # ERP: 7 ชิ้น ราคา 50 โปร 3 ชิ้น 100 = 2 ชุด x 100 + 1 x 50 = 250
        order = Order(promotions=[QtyPromotion(product_id=1, promo_type="bundle_price", min_qty=Decimal("3"), bundle_price=Decimal("100"))])
        order.add_product(line(1, "7", "50"))
        self.assertEqual(order.grand_total(), Decimal("250.00"))

    def test_percent_discount_is_on_complete_sets_only(self) -> None:
        promo = QtyPromotion(product_id=1, promo_type="discount", min_qty=Decimal("2"),
                             discount_type="percent", discount_value=Decimal("10"))
        self.assertEqual(promotion_discounts([line(1, "5", "45")], [promo]), {0: Decimal("18.00")})   # 2 ชุด x 2 x 45 x 10%

    def test_fixed_discount_is_per_set(self) -> None:
        promo = QtyPromotion(product_id=1, promo_type="discount", min_qty=Decimal("3"),
                             discount_type="fixed", discount_value=Decimal("10"))
        self.assertEqual(promotion_discounts([line(1, "7", "25")], [promo]), {0: Decimal("20.00")})

    def test_free_item_discounts_the_gift_line_up_to_what_is_in_the_bill(self) -> None:
        promo = QtyPromotion(product_id=1, promo_type="free_item", min_qty=Decimal("2"),
                             free_product_id=2, free_qty=Decimal("1"))
        lines = [line(1, "4", "100"), line(2, "1", "15")]
        # 2 ชุดได้แถม 2 แต่ในบิลมีของแถม 1 ชิ้น ลดได้ 1 ชิ้น
        self.assertEqual(promotion_discounts(lines, [promo]), {1: Decimal("15.00")})

    def test_the_promotion_uses_the_list_price_not_a_price_the_cashier_lowered(self) -> None:
        promo = QtyPromotion(product_id=1, promo_type="discount", min_qty=Decimal("1"),
                             discount_type="percent", discount_value=Decimal("10"))
        lowered = line(1, "1", "100")
        lowered.unit_price = Decimal("80")
        self.assertEqual(promotion_discounts([lowered], [promo]), {0: Decimal("10.00")})

    def test_not_enough_for_one_set_means_no_discount(self) -> None:
        promo = QtyPromotion(product_id=1, promo_type="bundle_price", min_qty=Decimal("3"), bundle_price=Decimal("100"))
        self.assertEqual(promotion_discounts([line(1, "2", "50")], [promo]), {})

    def test_a_promotion_discount_needs_no_approval(self) -> None:
        order = Order(promotions=[QtyPromotion(product_id=1, promo_type="bundle_price", min_qty=Decimal("3"), bundle_price=Decimal("100"))])
        order.add_product(line(1, "3", "50"))
        self.assertEqual(order.adjustment_total(), Decimal("0.00"))
        self.assertEqual(order.discount_total(), Decimal("50.00"))


class PromotionSaleTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.db.execute("INSERT INTO local_cashiers (id, server_id, code, name, pin_hash, synced_at) VALUES (77, 900, 'POP001', 'T', ?, ?)", (pin_hash("1234"), now()))
        self.db.execute("INSERT INTO products (id, server_id, sku, name, unit_name, updated_at) VALUES (1, 101, 'P1', 'น้ำปลา', 'ขวด', ?)", (now(),))
        self.db.execute(
            """INSERT INTO qty_promotions (product_id, promo_type, min_qty, bundle_price, starts_date, ends_date)
               VALUES (1, 'bundle_price', '3', '100', '2026-10-01', '2026-10-31')""")
        self.db.commit()
        self.pos = PosService(self.db)
        self.shift_id = self.pos.open_shift(1, "HQ-01", 77, Decimal("0"))

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def test_only_promotions_running_on_the_day_are_used(self) -> None:
        self.assertEqual(len(active_promotions(self.db, date(2026, 10, 4))), 1)
        self.assertEqual(active_promotions(self.db, date(2026, 11, 1)), [])

    def test_a_promotion_bill_reaches_erp_at_the_promotion_price_without_a_manual_discount(self) -> None:
        order = Order(promotions=active_promotions(self.db, date(2026, 10, 4)))
        order.add_product(line(1, "7", "50"))
        self.pos.checkout(document_no="PR-1", branch_id=1, terminal_id="HQ-01", shift_id=self.shift_id, cashier_id=77,
                          lines=order.to_cart_lines(), payment_method="cash", paid_amount=Decimal("250"), sale_uuid="promo-1")
        sale = self.db.execute("SELECT grand_total, discount_total FROM sales").fetchone()
        self.assertEqual((Decimal(sale["grand_total"]), Decimal(sale["discount_total"])), (Decimal("250.00"), Decimal("100.00")))

        self.pos.bind_server_shift(self.shift_id, 500)
        calls = []

        class Api:
            def post(self, path, payload, *, idempotency_key=None):
                calls.append(payload)
                return {"success": True, "receipt_no": "R-1"}

        SyncService(self.db, Api()).sync_sale("promo-1")
        payload = calls[0]
        item = payload["items"][0]
        self.assertAlmostEqual(float(Decimal(item["qty"]) * Decimal(item["unit_price"])), 250.0, places=2)
        self.assertNotIn("manual_discount_amount", payload, "ส่วนลดโป ERP คิดเอง ห้ามส่งเป็นส่วนลดที่คนให้")


if __name__ == "__main__":
    unittest.main()
