"""1 แถม 1 / 2 แถม 1 ของสินค้าตัวเดียวกัน: ของแถมนับรวมอยู่ในจำนวนที่ซื้อ (บั๊กเดิมแถมทุกชิ้น)"""
from __future__ import annotations
from dataclasses import dataclass
from decimal import Decimal

import pytest

from pos_python.promotions import QtyPromotion, promotion_discounts


@dataclass
class Line:
    product_id: int
    qty: Decimal
    unit_price: Decimal
    list_price: Decimal | None = None


def free(min_qty, free_qty, product=1, gift=1):
    return QtyPromotion(product_id=product, promo_type="free_item", min_qty=Decimal(min_qty),
                        free_product_id=gift, free_qty=Decimal(free_qty))


@pytest.mark.parametrize("qty, expected", [(1, "0"), (2, "50.00"), (3, "50.00"), (4, "100.00"), (5, "100.00")])
def test_buy_one_get_one_same_product(qty, expected):
    got = sum(promotion_discounts([Line(1, Decimal(qty), Decimal("50"))], [free(1, 1)]).values(), Decimal("0"))
    assert got == Decimal(expected)


@pytest.mark.parametrize("qty, expected", [(2, "0"), (3, "39.00"), (5, "39.00"), (6, "78.00")])
def test_buy_two_get_one_same_product(qty, expected):
    got = sum(promotion_discounts([Line(1, Decimal(qty), Decimal("39"))], [free(2, 1)]).values(), Decimal("0"))
    assert got == Decimal(expected)


def test_gift_of_another_product_is_unchanged():
    lines = [Line(1, Decimal("3"), Decimal("50")), Line(2, Decimal("5"), Decimal("20"))]
    assert promotion_discounts(lines, [free(1, 1, product=1, gift=2)]) == {1: Decimal("60.00")}
