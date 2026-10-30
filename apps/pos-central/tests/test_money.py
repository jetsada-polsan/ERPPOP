from decimal import Decimal

import pytest

from services.money import (
    allocate_discount,
    allocate_display_amounts,
    cart_subtotal,
    change_for,
    discount_for,
    decimal_text,
    line_amount,
    money,
)
from services.promotions import quantity_promotion_discount


def test_money_uses_half_up_rounding_like_erp():
    assert money("1.005") == Decimal("1.01")
    assert discount_for("0.15", percent="10") == Decimal("0.02")


def test_bill_rounds_after_summing_exact_line_amounts():
    # Each row displays 0.02, but ERP sums 0.01665 + 0.01665 then rounds once.
    assert money(line_amount("0.333", "0.05")) == Decimal("0.02")
    assert cart_subtotal([("0.333", "0.05"), ("0.333", "0.05")]) == Decimal("0.03")


def test_displayed_line_amounts_reconcile_to_bill_level_rounding():
    lines = [line_amount("0.333", "0.05"), line_amount("0.333", "0.05")]
    displayed = allocate_display_amounts(lines)
    assert displayed == [Decimal("0.01"), Decimal("0.02")]
    assert sum(displayed) == cart_subtotal([("0.333", "0.05"), ("0.333", "0.05")])


def test_discount_is_clamped_and_change_is_never_negative():
    assert discount_for("10.00", amount="12.345") == Decimal("10.00")
    assert change_for("10.00", "10.01") == Decimal("0.00")
    assert change_for("10.10", "10.01") == Decimal("0.09")


def test_discount_allocation_matches_payable_total():
    lines = [("2", "10.00"), ("1", "10.00")]
    net_prices = allocate_discount(lines, "3.00")
    assert net_prices == [Decimal("9.00000000"), Decimal("9.00000000")]
    assert cart_subtotal([("2", net_prices[0]), ("1", net_prices[1])]) == Decimal("27.00")


def test_discount_allocation_preserves_fractional_satang_on_each_line():
    lines = [("0.333", "0.05"), ("0.333", "0.05")]
    net_prices = allocate_discount(lines, "0.01")
    assert cart_subtotal(list(zip((qty for qty, _ in lines), net_prices))) == Decimal("0.02")


def test_quantity_and_price_text_keep_all_eight_calculation_places():
    assert decimal_text("0.12345678") == "0.12345678"
    assert decimal_text("29.00000000") == "29"


def test_discount_allocation_can_skip_erp_priced_scale_labels():
    lines = [("1", "5.00"), ("0.275", "40.00")]
    net_prices = allocate_discount(lines, "1.00", eligible=[True, False])
    assert net_prices == [Decimal("4.00000000"), Decimal("40.00000000")]
    with pytest.raises(ValueError, match="safely discounted"):
        allocate_discount(lines, "5.01", eligible=[True, False])


@pytest.mark.parametrize(("promotion", "lines", "expected"), [
    ({"product_id": 1, "promo_type": "discount", "min_qty": "2", "discount_type": "percent", "discount_value": "10"},
     [{"product_id": 1, "qty": "5", "unit_price": "45"}], "18.00"),
    ({"product_id": 1, "promo_type": "discount", "min_qty": "3", "discount_type": "fixed", "discount_value": "10"},
     [{"product_id": 1, "qty": "7", "unit_price": "25"}], "20.00"),
    ({"product_id": 1, "promo_type": "bundle_price", "min_qty": "3", "bundle_price": "100"},
     [{"product_id": 1, "qty": "6", "unit_price": "50"}], "100.00"),
    ({"product_id": 1, "promo_type": "free_item", "min_qty": "2", "free_product_id": 2, "free_qty": "1"},
     [{"product_id": 1, "qty": "4", "unit_price": "30"}, {"product_id": 2, "qty": "1", "unit_price": "15"}], "15.00"),
])
def test_quantity_promotion_formulas_match_erp(promotion, lines, expected):
    assert quantity_promotion_discount(lines, [promotion]) == Decimal(expected)
