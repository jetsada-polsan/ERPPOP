from __future__ import annotations

import sqlite3
from dataclasses import dataclass
from decimal import Decimal

from .services import CartLine


def ean13_check_digit(twelve_digits: str) -> int | None:
    if len(twelve_digits) != 12 or not twelve_digits.isdigit():
        return None
    weighted = sum(int(digit) * (1 if index % 2 == 0 else 3) for index, digit in enumerate(twelve_digits))
    return (10 - weighted % 10) % 10


@dataclass(frozen=True)
class ScaleLabel:
    plu: str
    total_price: Decimal


def decode_scale_label(code: str) -> ScaleLabel | None:
    """POPSTAR scale profile: PLU 800/801 (6) + total price in satang (6) + EAN check digit."""
    scanned = code.strip()
    if len(scanned) != 13 or not scanned.isdigit() or not (scanned.startswith("800") or scanned.startswith("801")):
        return None
    if ean13_check_digit(scanned[:12]) != int(scanned[12]):
        return None
    return ScaleLabel(plu=scanned[:6], total_price=Decimal(scanned[6:12]) / Decimal("100"))


def scale_cart_line(db: sqlite3.Connection, code: str) -> CartLine:
    """Turn a one-time scale label into a priced cart line; the raw scan remains in source_barcode."""
    label = decode_scale_label(code)
    if not label:
        raise ValueError("ป้ายเครื่องชั่งไม่ถูกต้อง หรือ check digit ไม่ตรง")
    product = db.execute(
        """SELECT p.id, p.name, p.unit_name, b.price
        FROM products p LEFT JOIN product_barcodes b ON b.product_id = p.id AND b.barcode = ?
        WHERE p.active = 1 AND (p.sku = ? OR b.barcode = ?) LIMIT 1""",
        (label.plu, label.plu, label.plu),
    ).fetchone()
    if not product:
        raise ValueError(f"ไม่พบสินค้าสำหรับ PLU เครื่องชั่ง {label.plu}")
    unit_price = Decimal(str(product["price"] or 0))
    if unit_price <= 0:
        raise ValueError(f"สินค้า PLU {label.plu} ยังไม่ได้ตั้งราคาขายต่อหน่วย")
    qty = label.total_price / unit_price
    return CartLine(
        product_id=int(product["id"]), qty=qty, unit_price=unit_price,
        barcode=label.plu, source_barcode=code.strip(), price_version="scale-label",
    )
