from __future__ import annotations

from decimal import Decimal, ROUND_HALF_UP
from typing import Iterable

from .models import CartLine, CartPricing, FreeItem, Money, PricedItem, Promotion


HUNDRED = Decimal("100")
CENT = Decimal("0.01")
UNIT_PRECISION = Decimal("0.0001")
ZERO = Decimal("0")


def money(value: Decimal) -> Money:
    return value.quantize(CENT, rounding=ROUND_HALF_UP)


def unit_price(value: Decimal) -> Money:
    return value.quantize(UNIT_PRECISION, rounding=ROUND_HALF_UP)


def positive(value: Decimal | None) -> Decimal:
    return max(ZERO, value or ZERO)


def _sets(quantity: Decimal, size: Decimal) -> int:
    return int(positive(quantity) // max(Decimal("1"), positive(size)))


def price_cart(
    cart: Iterable[CartLine],
    promotions: Iterable[Promotion],
    vat_rate: Decimal = Decimal("7"),
    bill_discount: Decimal = ZERO,
) -> CartPricing:
    lines = tuple(cart)
    promotion_list = tuple(promotions)
    gross_by_line = tuple(positive(line.product.pos_price) * positive(line.qty) for line in lines)
    subtotal = money(sum(gross_by_line, ZERO))
    promo_discount = ZERO

    for promotion in promotion_list:
        if promotion.promo_type not in {"discount", "bundle_price"} or promotion.product_id is None:
            continue
        matched = [(line, gross) for line, gross in zip(lines, gross_by_line) if line.product.id == promotion.product_id]
        quantity = sum((positive(line.qty) for line, _ in matched), ZERO)
        sets = _sets(quantity, promotion.min_qty)
        if not sets or not matched:
            continue
        line_price = positive(matched[0][0].product.pos_price)
        if promotion.promo_type == "bundle_price":
            promo_discount += max(ZERO, Decimal(sets) * (promotion.min_qty * line_price - positive(promotion.bundle_price)))
            continue
        base = Decimal(sets) * promotion.min_qty * line_price
        if promotion.discount_type == "percent":
            discount = base * positive(promotion.discount_value) / HUNDRED
        else:
            discount = positive(promotion.discount_value) * Decimal(sets)
        promo_discount += min(base, max(ZERO, discount))

    for promotion in promotion_list:
        if promotion.promo_type not in {"mix_bundle", "mix_free"}:
            continue
        group = set(promotion.product_ids)
        units = sorted(
            (
                line.product.pos_price
                for line in lines
                if line.product.id in group and line.product.pos_price > ZERO
                for _ in range(int(positive(line.qty)))
            ),
            reverse=True,
        )
        need = int(positive(promotion.min_qty))
        if not need:
            continue
        if promotion.promo_type == "mix_bundle":
            sets = len(units) // need
            promo_discount += max(ZERO, sum(units[: sets * need], ZERO) - Decimal(sets) * positive(promotion.bundle_price))
        else:
            free = int(positive(promotion.free_qty))
            sets = len(units) // (need + free) if free else 0
            promo_discount += sum(sorted(units)[: sets * free], ZERO)

    paid = subtotal - promo_discount
    bill_gifts: list[Promotion] = []
    for promotion in promotion_list:
        if promotion.promo_type == "bill_addon":
            addon = next((line for line in lines if line.product.id == promotion.product_id), None)
            if addon is None:
                continue
            addon_gross = positive(addon.qty) * positive(addon.product.pos_price)
            if paid - addon_gross < positive(promotion.min_amount):
                continue
            quantity = min(positive(addon.qty), positive(promotion.free_qty) or Decimal("1"))
            promo_discount += max(ZERO, quantity * (positive(addon.product.pos_price) - positive(promotion.bundle_price)))
            continue
        if promotion.promo_type not in {"bill_discount", "bill_free_item"} or paid < positive(promotion.min_amount):
            continue
        if promotion.promo_type == "bill_free_item":
            bill_gifts.append(promotion)
            continue
        if promotion.discount_type == "percent":
            discount = paid * positive(promotion.discount_value) / HUNDRED
        else:
            discount = positive(promotion.discount_value)
        if promotion.max_discount is not None:
            discount = min(discount, positive(promotion.max_discount))
        promo_discount += min(paid, max(ZERO, discount))

    promo_discount = money(min(subtotal, promo_discount))
    manual_discount = money(min(subtotal - promo_discount, positive(bill_discount)))
    total = money(max(ZERO, subtotal - promo_discount - manual_discount))
    rate = positive(vat_rate)
    vat = money(total * rate / (HUNDRED + rate))
    before_vat = money(total - vat)
    gross_total = sum(gross_by_line, ZERO)
    priced_items = [
        PricedItem(
            product_id=line.product.id,
            qty=positive(line.qty),
            unit_price=unit_price(max(ZERO, gross - (promo_discount + manual_discount) * gross / gross_total) / (positive(line.qty) or Decimal("1"))),
            barcode=line.barcode,
            barcode_type=line.barcode_type,
        )
        for line, gross in zip(lines, gross_by_line)
    ]
    free_items: list[FreeItem] = []
    for promotion in promotion_list:
        if promotion.promo_type != "free_item" or promotion.free_product is None or promotion.product_id is None:
            continue
        quantity = sum((positive(line.qty) for line in lines if line.product.id == promotion.product_id), ZERO)
        free_qty = Decimal(_sets(quantity, promotion.min_qty)) * positive(promotion.free_qty)
        if free_qty > ZERO:
            priced_items.append(PricedItem(promotion.free_product.id, free_qty, ZERO))
            free_items.append(FreeItem(promotion.free_product.id, promotion.free_product.sku_code, promotion.free_product.name_th, free_qty, promotion.name))
    for promotion in bill_gifts:
        if promotion.free_product is None or positive(promotion.free_qty) <= ZERO:
            continue
        priced_items.append(PricedItem(promotion.free_product.id, positive(promotion.free_qty), ZERO))
        free_items.append(FreeItem(promotion.free_product.id, promotion.free_product.sku_code, promotion.free_product.name_th, positive(promotion.free_qty), promotion.name))
    return CartPricing(subtotal, promo_discount, manual_discount, total, vat, before_vat, tuple(priced_items), tuple(free_items))

