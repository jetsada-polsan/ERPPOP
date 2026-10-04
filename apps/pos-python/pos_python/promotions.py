"""โปรซื้อครบจำนวน (ซื้อ X ลด / ราคาชุด / แถม) — ต้องคิดเหมือน ERP ทุกบาท

ERP หักส่วนลดโปรเองทุกบิล (PosPricingGuard::qtyPromotionDiscount) แล้วเทียบกับยอดที่เครื่องส่งมา
ถ้าเครื่องไม่หักด้วย ยอดไม่ตรง บิลถูกปฏิเสธทั้งที่เก็บเงินลูกค้าไปแล้ว — สูตรที่นี่จึงลอกจาก ERP ตรงๆ:
- ใช้บรรทัดแรกของสินค้านั้นในบิลเป็นตัวตั้ง (ERP ใช้ firstWhere)
- ใช้ราคาตั้ง ไม่ใช่ราคาที่แคชเชียร์แก้
- จำนวนชุด = ปัดลง(จำนวน / ขั้นต่ำ)
ส่วนลดโปรไม่ต้องมีคนอนุมัติ เพราะ ERP คิดเองจากโปรที่ตั้งไว้ ไม่ใช่คนหน้าร้านกำหนด
"""
from __future__ import annotations

import sqlite3
from dataclasses import dataclass
from datetime import date
from decimal import ROUND_FLOOR, Decimal
from typing import Iterable, Protocol


class PromoLine(Protocol):
    product_id: int
    qty: Decimal
    list_price: Decimal | None
    unit_price: Decimal


@dataclass(frozen=True)
class QtyPromotion:
    product_id: int
    promo_type: str
    min_qty: Decimal
    free_product_id: int | None = None
    free_qty: Decimal = Decimal("0")
    discount_type: str | None = None
    discount_value: Decimal = Decimal("0")
    bundle_price: Decimal = Decimal("0")
    name: str = ""


def _dec(value) -> Decimal:
    return Decimal(str(value)) if value not in (None, "") else Decimal("0")


def _price(line: PromoLine) -> Decimal:
    return line.list_price if line.list_price is not None else line.unit_price


def promotion_discounts(lines: list[PromoLine], promotions: Iterable[QtyPromotion]) -> dict[int, Decimal]:
    """ส่วนลดโปรต่อบรรทัด {index ของบรรทัด: ยอดลด} ลงที่บรรทัดตัวตั้ง หรือบรรทัดของแถม"""
    result: dict[int, Decimal] = {}

    def first(product_id: int | None) -> int | None:
        for index, line in enumerate(lines):
            if line.product_id == product_id:
                return index
        return None

    for promotion in promotions:
        trigger_index = first(promotion.product_id)
        if trigger_index is None or promotion.min_qty <= 0:
            continue
        trigger = lines[trigger_index]
        sets = (trigger.qty / promotion.min_qty).to_integral_value(rounding=ROUND_FLOOR)
        if sets <= 0:
            continue
        target, amount = trigger_index, Decimal("0")
        if promotion.promo_type == "discount":
            if promotion.discount_type == "percent":
                amount = sets * promotion.min_qty * _price(trigger) * promotion.discount_value / 100
            else:
                amount = sets * promotion.discount_value
        elif promotion.promo_type == "bundle_price":
            amount = max(Decimal("0"), sets * promotion.min_qty * _price(trigger) - sets * promotion.bundle_price)
        elif promotion.promo_type == "free_item":
            gift_index = first(promotion.free_product_id)
            if gift_index is None:
                continue
            gift = lines[gift_index]
            target = gift_index
            amount = min(gift.qty, sets * promotion.free_qty) * _price(gift)
        if amount > 0:
            result[target] = result.get(target, Decimal("0")) + amount

    return {index: amount.quantize(Decimal("0.01")) for index, amount in result.items()}


def replace_promotions(db: sqlite3.Connection, rows: list[dict]) -> int:
    """เก็บโปรจาก ERP ลงเครื่อง (แทนของเดิมทั้งหมด) แปลงรหัสสินค้า ERP เป็นรหัสในเครื่อง"""
    local = {int(row["server_id"]): int(row["id"]) for row in db.execute(
        "SELECT id, server_id FROM products WHERE server_id IS NOT NULL")}
    saved = 0
    db.execute("DELETE FROM qty_promotions")
    for row in rows:
        product_id = local.get(int(row.get("product_id") or 0))
        if product_id is None:
            continue
        free_id = local.get(int(row.get("free_product_id") or 0)) if row.get("free_product_id") else None
        db.execute(
            """INSERT INTO qty_promotions (server_id, name, promo_type, product_id, min_qty, free_product_id, free_qty,
               discount_type, discount_value, bundle_price, starts_date, ends_date)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (row.get("id"), row.get("name") or "", row.get("promo_type"), product_id, str(_dec(row.get("min_qty"))),
             free_id, str(_dec(row.get("free_qty"))), row.get("discount_type"), str(_dec(row.get("discount_value"))),
             str(_dec(row.get("bundle_price"))), row.get("starts_date"), row.get("ends_date")),
        )
        saved += 1
    return saved


def active_promotions(db: sqlite3.Connection, today: date | None = None) -> list[QtyPromotion]:
    day = (today or date.today()).isoformat()
    rows = db.execute(
        """SELECT * FROM qty_promotions
           WHERE (starts_date IS NULL OR substr(starts_date, 1, 10) <= ?)
             AND (ends_date IS NULL OR substr(ends_date, 1, 10) >= ?)
           ORDER BY id""", (day, day)).fetchall()
    return [QtyPromotion(
        product_id=int(row["product_id"]), promo_type=str(row["promo_type"]), min_qty=_dec(row["min_qty"]),
        free_product_id=row["free_product_id"], free_qty=_dec(row["free_qty"]), discount_type=row["discount_type"],
        discount_value=_dec(row["discount_value"]), bundle_price=_dec(row["bundle_price"]), name=str(row["name"] or ""),
    ) for row in rows]
