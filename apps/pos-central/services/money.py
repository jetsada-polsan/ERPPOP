"""Exact POS arithmetic shared by cart, discounts, and payment screens.

The ERP uses decimal arithmetic at eight places for quantities and line math,
then rounds the payable amount to satang using half-up rounding. Keep the same
order here so the amount shown, collected, stored, and sent to ERP agree.
"""
from decimal import Decimal, ROUND_HALF_UP


CENT = Decimal("0.01")
CALC_SCALE = Decimal("0.00000001")
ZERO = Decimal("0")


def decimal_value(value) -> Decimal:
    """Convert user, SQLite, or JSON values without importing binary float noise."""
    if value is None or value == "":
        return ZERO
    if isinstance(value, Decimal):
        return value
    return Decimal(str(value).replace(",", "").strip())


def calc(value) -> Decimal:
    """Match ERP's eight-place quantity/cost calculations (ROUND_HALF_UP)."""
    return decimal_value(value).quantize(CALC_SCALE, rounding=ROUND_HALF_UP)


def money(value) -> Decimal:
    """Round payable money to satang, matching DecimalMath::DISPLAY_MONEY_SCALE."""
    return decimal_value(value).quantize(CENT, rounding=ROUND_HALF_UP)


def line_amount(qty, unit_price) -> Decimal:
    """Return an unrounded-to-satang line amount at ERP's eight-place scale."""
    return calc(calc(qty) * calc(unit_price))


def cart_subtotal(lines) -> Decimal:
    """Sum exact line amounts first, then round the bill once to satang."""
    return money(sum((line_amount(qty, price) for qty, price in lines), ZERO))


def discount_for(subtotal, amount=0, percent=None) -> Decimal:
    """Calculate and clamp a fixed or percentage discount using half-up rounding."""
    base = max(ZERO, money(subtotal))
    if percent is not None:
        requested = money(base * max(ZERO, min(Decimal("100"), decimal_value(percent))) / 100)
    else:
        requested = money(max(ZERO, decimal_value(amount)))
    return min(base, requested)


def change_for(received, payable) -> Decimal:
    """Cash change cannot be negative and is always expressed in satang."""
    return money(max(ZERO, money(received) - money(payable)))


def included_vat(total, rate=7) -> Decimal:
    """Extract VAT included in a payable total and round the tax to satang."""
    total_amount = money(total)
    rate_value = decimal_value(rate)
    if total_amount <= ZERO or rate_value <= ZERO:
        return ZERO.quantize(CENT)
    return money(total_amount * rate_value / (Decimal("100") + rate_value))


def allocate_discount(lines, discount, eligible=None) -> list[Decimal]:
    """Allocate a bill discount across lines; return the resulting net unit prices.

    ``lines`` contains ``(qty, unit_price)`` pairs. ``eligible`` can exclude a
    server-priced scale label: that price is re-derived from its barcode during
    ERP sync and cannot safely be changed by the desktop client.
    """
    values = [(decimal_value(qty), decimal_value(price)) for qty, price in lines]
    gross = [line_amount(qty, price) for qty, price in values]
    allowed = list(eligible) if eligible is not None else [True] * len(values)
    if len(allowed) != len(values):
        raise ValueError("eligible flags must match the number of cart lines")
    discount = money(discount)
    eligible_total = sum((gross[i] for i, can_discount in enumerate(allowed) if can_discount), ZERO)
    if discount < ZERO or discount > money(eligible_total):
        raise ValueError("discount exceeds the amount that can be safely discounted")

    eligible_indexes = [i for i, can_discount in enumerate(allowed) if can_discount and gross[i] > 0]
    # The customer-facing discount is in cents, but prices and quantities in
    # ERP are kept at eight decimals. Allocate the exact discount value by line
    # value, then round only the resulting unit price to ERP's COST_SCALE. This
    # preserves fractional satang in untouched lines; rounding each line to
    # cents first can make a 1-satang discount produce no reduction at all.
    actual_discount = min(discount, eligible_total)
    shares: dict[int, Decimal] = {}
    allocated = ZERO
    for index in eligible_indexes[:-1]:
        share = actual_discount * gross[index] / eligible_total if eligible_total else ZERO
        shares[index] = share
        allocated += share
    if eligible_indexes:
        shares[eligible_indexes[-1]] = actual_discount - allocated

    result = []
    for index, (qty, unit_price) in enumerate(values):
        if qty <= 0:
            raise ValueError("cart quantity must be greater than zero")
        line_discount = shares.get(index, ZERO)
        line_net = max(ZERO, gross[index] - line_discount)
        result.append(calc(line_net / qty))

    # The ERP validates the bill after multiplying the submitted unit prices.
    # If eight-place unit-price precision cannot represent this allocation
    # without changing the payable cent, reject it instead of accepting a
    # silently different total.
    submitted = money(sum(
        (line_amount(qty, price) for (qty, _), price in zip(values, result)), ZERO
    ))
    target = max(ZERO, money(sum(gross, ZERO) - discount))
    if submitted != target:
        raise ValueError("discount cannot be represented at ERP's eight-decimal unit-price precision")
    return result


def money_text(value) -> str:
    return f"{money(value):.2f}"


def decimal_text(value) -> str:
    """Format a quantity/unit price without throwing away its eight-place value."""
    text = f"{calc(value):.8f}".rstrip("0").rstrip(".")
    return text or "0"


def allocate_display_amounts(amounts) -> list[Decimal]:
    """Round line displays while making their displayed sum equal the bill."""
    exact = [calc(value) for value in amounts]
    displayed = [money(value) for value in exact]
    target = money(sum(exact, ZERO))
    difference = target - sum(displayed, ZERO)
    cents = int(abs(difference) / CENT)
    if cents == 0:
        return displayed

    # Give cents to lines rounded down most, or remove cents from lines rounded
    # up most. This is deterministic and keeps the bill-level HALF_UP total.
    if difference > 0:
        candidates = sorted(
            range(len(exact)),
            key=lambda i: (-(exact[i] - displayed[i]), i),
        )
        adjustment = CENT
    else:
        candidates = sorted(
            (i for i in range(len(exact)) if displayed[i] >= CENT),
            key=lambda i: (exact[i] - displayed[i], i),
        )
        adjustment = -CENT

    for index in candidates:
        if cents == 0:
            break
        displayed[index] += adjustment
        cents -= 1
    if cents:
        raise ValueError("line amounts cannot be rounded to the bill subtotal")
    return displayed
