from decimal import Decimal
import unittest

from jet_pos.models import CartLine, Product, Promotion
from jet_pos.pricing import price_cart


def product(product_id: int, price: str = "100") -> Product:
    return Product(product_id, f"SKU{product_id:03d}", f"สินค้า {product_id}", Decimal(price))


def line(item: Product, qty: str) -> CartLine:
    return CartLine(item, Decimal(qty))


class PricingParityTest(unittest.TestCase):
    def test_discount_percent_applies_to_complete_sets(self) -> None:
        item = product(1)
        result = price_cart([line(item, "3")], [Promotion(1, "ลด 10%", "discount", product_id=1, min_qty=Decimal("2"), discount_type="percent", discount_value=Decimal("10"))])
        self.assertEqual(result.promo_discount, Decimal("20.00"))
        self.assertEqual(result.total, Decimal("280.00"))

    def test_bundle_price_applies_to_complete_sets(self) -> None:
        item = product(1)
        result = price_cart([line(item, "2")], [Promotion(1, "2 ชิ้น 150", "bundle_price", product_id=1, min_qty=Decimal("2"), bundle_price=Decimal("150"))])
        self.assertEqual(result.promo_discount, Decimal("50.00"))

    def test_free_item_is_added_at_zero_price(self) -> None:
        paid, gift = product(1), product(2, "35")
        result = price_cart([line(paid, "2")], [Promotion(1, "ซื้อ 2 แถม 1", "free_item", product_id=1, min_qty=Decimal("2"), free_product=gift, free_qty=Decimal("1"))])
        self.assertEqual(result.total, Decimal("200.00"))
        self.assertEqual(result.items[-1].unit_price, Decimal("0"))
        self.assertEqual(result.free_items[0].qty, Decimal("1"))

    def test_mix_bundle_uses_group_price(self) -> None:
        first, second = product(1, "100"), product(2, "80")
        result = price_cart([line(first, "1"), line(second, "1")], [Promotion(1, "คละ 2 ชิ้น", "mix_bundle", product_ids=(1, 2), min_qty=Decimal("2"), bundle_price=Decimal("120"))])
        self.assertEqual(result.promo_discount, Decimal("60.00"))

    def test_mix_free_makes_cheapest_item_free(self) -> None:
        first, second = product(1, "100"), product(2, "80")
        result = price_cart([line(first, "2"), line(second, "2")], [Promotion(1, "ซื้อ 3 แถม 1", "mix_free", product_ids=(1, 2), min_qty=Decimal("3"), free_qty=Decimal("1"))])
        self.assertEqual(result.promo_discount, Decimal("80.00"))

    def test_bill_discount_honours_cap(self) -> None:
        item = product(1)
        result = price_cart([line(item, "3")], [Promotion(1, "ลดทั้งบิล", "bill_discount", min_amount=Decimal("200"), discount_type="percent", discount_value=Decimal("20"), max_discount=Decimal("50"))])
        self.assertEqual(result.promo_discount, Decimal("50.00"))

    def test_bill_free_item_requires_minimum_bill(self) -> None:
        item, gift = product(1), product(2)
        promotion = Promotion(1, "แถมท้ายบิล", "bill_free_item", min_amount=Decimal("200"), free_product=gift, free_qty=Decimal("1"))
        self.assertEqual(len(price_cart([line(item, "1")], [promotion]).free_items), 0)
        self.assertEqual(len(price_cart([line(item, "2")], [promotion]).free_items), 1)

    def test_bill_addon_only_discounts_when_other_items_reach_threshold(self) -> None:
        main, addon = product(1, "200"), product(2, "100")
        promotion = Promotion(1, "แลกซื้อ", "bill_addon", product_id=2, min_amount=Decimal("200"), bundle_price=Decimal("30"), free_qty=Decimal("1"))
        result = price_cart([line(main, "1"), line(addon, "1")], [promotion])
        self.assertEqual(result.promo_discount, Decimal("70.00"))


if __name__ == "__main__":
    unittest.main()

