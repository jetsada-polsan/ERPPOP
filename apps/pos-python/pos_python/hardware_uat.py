"""Small hardware-contract helpers shared by the Windows UAT command.

The scanner is intentionally treated as a keyboard-wedge device: the same
code must resolve whether it came from a USB scanner or was pasted for UAT.
The printer path is kept in :mod:`pos_python.printers` and is only invoked by
the command when the operator explicitly asks to send a real test job.
"""
from __future__ import annotations

import sqlite3
from dataclasses import dataclass
from decimal import Decimal

from .barcode import decode_scale_label, scale_cart_line
from .services import PosService


@dataclass(frozen=True)
class HardwareScanResult:
    code: str
    kind: str
    product_id: int
    name: str
    qty: Decimal
    unit_price: Decimal


def inspect_scan(db: sqlite3.Connection, code: str) -> HardwareScanResult:
    """Resolve one normal barcode or one configured 800/801 scale label."""
    scanned = code.strip()
    if not scanned:
        raise ValueError("บาร์โค้ดว่าง")

    service = PosService(db)
    product = service.lookup_barcode(scanned)
    if product:
        price = service.effective_price(int(product["id"]), product["price"] or 0)[0]
        return HardwareScanResult(
            code=scanned,
            kind="normal",
            product_id=int(product["id"]),
            name=str(product["name"]),
            qty=Decimal("1"),
            unit_price=price,
        )

    if decode_scale_label(db, scanned) is not None:
        line = scale_cart_line(db, scanned)
        product = db.execute(
            "SELECT name FROM products WHERE id = ? AND active = 1", (line.product_id,)
        ).fetchone()
        if not product:
            raise ValueError(f"ไม่พบสินค้าในเครื่องสำหรับป้าย {scanned}")
        return HardwareScanResult(
            code=scanned,
            kind="scale",
            product_id=line.product_id,
            name=str(product["name"]),
            qty=line.qty,
            unit_price=line.unit_price,
        )

    raise ValueError(f"ไม่พบบาร์โค้ด {scanned}")
