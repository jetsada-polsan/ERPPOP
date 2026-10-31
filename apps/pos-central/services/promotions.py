"""Mirror ERP's quantity-promotion discount calculation for POS previews."""
from decimal import ROUND_FLOOR, Decimal

from services.money import ZERO, decimal_value, money


def _value(obj, name, default=None):
    if isinstance(obj, dict):
        return obj.get(name, default)
    return getattr(obj, name, default)


def quantity_promotion_discount(lines, promotions):
    """Compute the total buy-N promotion discount using ERP's first-line rule.

    Lines are dicts (or objects) with product_id, qty, and unit_price. ERP uses
    the first matching product line and its list price for each promotion.
    """
    discount = ZERO

    def first(product_id):
        for line in lines:
            if int(_value(line, "product_id", 0) or 0) == int(product_id or 0):
                return line
        return None

    for promo in promotions:
        trigger = first(_value(promo, "product_id"))
        minimum = decimal_value(_value(promo, "min_qty", 0))
        if trigger is None or minimum <= 0:
            continue
        qty = decimal_value(_value(trigger, "qty", 0))
        sets = (qty / minimum).to_integral_value(rounding=ROUND_FLOOR)
        if sets <= 0:
            continue

        promo_type = _value(promo, "promo_type")
        price = decimal_value(_value(trigger, "unit_price", 0))
        amount = ZERO
        if promo_type == "discount":
            if _value(promo, "discount_type") == "percent":
                amount = sets * minimum * price * decimal_value(_value(promo, "discount_value", 0)) / 100
            else:
                amount = sets * decimal_value(_value(promo, "discount_value", 0))
        elif promo_type == "bundle_price":
            amount = max(ZERO, sets * minimum * price - sets * decimal_value(_value(promo, "bundle_price", 0)))
        elif promo_type == "free_item":
            gift = first(_value(promo, "free_product_id"))
            if gift is None:
                continue
            free_qty = min(
                decimal_value(_value(gift, "qty", 0)),
                sets * decimal_value(_value(promo, "free_qty", 0)),
            )
            amount = free_qty * decimal_value(_value(gift, "unit_price", 0))
        discount += max(ZERO, amount)

    return money(discount)
