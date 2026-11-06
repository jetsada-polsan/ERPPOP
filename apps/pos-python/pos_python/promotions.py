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
import json
from datetime import date, datetime, time, timezone
from decimal import ROUND_FLOOR, ROUND_HALF_UP, Decimal
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
    product_ids: tuple[int, ...] = ()
    min_amount: Decimal = Decimal("0")
    max_discount: Decimal | None = None
    free_sku: str = ""
    free_name: str = ""
    free_price: Decimal = Decimal("0")
    starts_at: str | None = None
    ends_at: str | None = None


def _dec(value) -> Decimal:
    return Decimal(str(value)) if value not in (None, "") else Decimal("0")


def _price(line: PromoLine) -> Decimal:
    return line.list_price if line.list_price is not None else line.unit_price


def promotion_discounts(lines: list[PromoLine], promotions: Iterable[QtyPromotion], *, rounded: bool = True, breakdown: list | None = None) -> dict[int, Decimal]:
    """ส่วนลดโปรต่อบรรทัด {index ของบรรทัด: ยอดลด} ลงที่บรรทัดตัวตั้ง หรือบรรทัดของแถม"""
    result: dict[int, Decimal] = {}

    def first(product_id: int | None) -> int | None:
        for index, line in enumerate(lines):
            if line.product_id == product_id:
                return index
        return None

    promotions = list(promotions)
    for promotion in promotions:
        if promotion.promo_type in ("mix_bundle", "mix_free"):
            units = sorted(( _price(line) for line in lines
                if line.product_id in promotion.product_ids and _price(line) > 0
                for _ in range(int(line.qty))), reverse=True)
            need, free = int(promotion.min_qty), int(promotion.free_qty)
            if need <= 0:
                continue
            if promotion.promo_type == "mix_bundle":
                sets = len(units) // need
                amount = max(Decimal("0"), sum(units[:sets * need], Decimal("0")) - sets * promotion.bundle_price)
            else:
                sets = len(units) // (need + free)
                amount = sum(list(reversed(units))[:sets * free], Decimal("0"))
            if amount:
                if breakdown is not None:
                    breakdown.append((promotion.name or promotion.promo_type, amount))
                target = next(i for i, line in enumerate(lines) if line.product_id in promotion.product_ids)
                result[target] = result.get(target, Decimal("0")) + amount
            continue
        if promotion.promo_type.startswith("bill_"):
            continue
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
            if breakdown is not None:
                breakdown.append((promotion.name or promotion.promo_type, amount))
            result[target] = result.get(target, Decimal("0")) + amount

    gift_value = Decimal("0")
    for promotion in promotions:
        if promotion.promo_type == "bill_free_item":
            index = first(promotion.free_product_id)
            if index is not None:
                gift_value += min(lines[index].qty, promotion.free_qty) * _price(lines[index])
    paid = sum((line.qty * _price(line) for line in lines), Decimal("0")) - gift_value - sum(result.values(), Decimal("0"))
    for promotion in promotions:
        kind = promotion.promo_type
        if not kind.startswith("bill_"):
            continue
        target = first(promotion.product_id) if kind == "bill_addon" else 0
        if target is None or not lines:
            continue
        if kind == "bill_addon":
            addon = lines[target]
            if paid - addon.qty * _price(addon) + Decimal("0.0001") < promotion.min_amount:
                continue
            amount = max(Decimal("0"), min(addon.qty, promotion.free_qty) * (_price(addon) - promotion.bundle_price))
        elif paid + Decimal("0.0001") < promotion.min_amount:
            continue
        elif kind == "bill_discount":
            amount = paid * promotion.discount_value / 100 if promotion.discount_type == "percent" else promotion.discount_value
            if promotion.max_discount is not None:
                amount = min(amount, promotion.max_discount)
            amount = min(paid, amount)
        else:
            target = first(promotion.free_product_id)
            if target is None:
                continue
            amount = min(lines[target].qty, promotion.free_qty) * _price(lines[target])
        if amount > 0 and breakdown is not None:
            breakdown.append((promotion.name or promotion.promo_type, amount))
        result[target] = result.get(target, Decimal("0")) + amount

    return {index: amount.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP) if rounded else amount for index, amount in result.items()}


def promotion_active(promotion: QtyPromotion, now: datetime | None = None) -> bool:
    now = now or datetime.now().astimezone()
    def parsed(value):
        result = datetime.fromisoformat(value.replace("Z", "+00:00"))
        return result.replace(tzinfo=now.tzinfo) if result.tzinfo is None else result
    return (not promotion.starts_at or parsed(promotion.starts_at) <= now) and (not promotion.ends_at or parsed(promotion.ends_at) >= now)


def replace_promotions(db: sqlite3.Connection, rows: list[dict]) -> int:
    local = {int(row["server_id"]): row for row in db.execute(
        "SELECT id, server_id, sku, name, price FROM products WHERE server_id IS NOT NULL")}
    saved = 0
    db.execute("DELETE FROM qty_promotions")
    for row in rows:
        kind = row.get("promo_type", "")
        product = local.get(int(row.get("product_id") or 0))
        if product is None and kind not in ("mix_bundle", "mix_free", "bill_discount", "bill_free_item"):
            continue
        gift = local.get(int(row.get("free_product_id") or 0))
        if kind in ("free_item", "bill_free_item") and gift is None:
            raise ValueError("โปรของแถมอ้างถึงสินค้าที่ไม่ได้ sync: โหลดสินค้าใหม่ก่อนโหลดโปร")
        group = [int(local[int(i)]["id"]) for i in row.get("product_ids", []) if int(i) in local]
        free = row.get("free_product") or {}
        db.execute(
            """INSERT INTO qty_promotions (server_id, name, promo_type, product_id, min_qty, free_product_id, free_qty,
               discount_type, discount_value, bundle_price, starts_date, ends_date,
               product_ids, min_amount, max_discount, free_sku, free_name, free_price, starts_at, ends_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (row.get("id"), row.get("name") or "", kind, product["id"] if product else 0, str(_dec(row.get("min_qty"))),
             gift["id"] if gift else None, str(_dec(row.get("free_qty", 1 if kind == "bill_addon" else 0))),
             row.get("discount_type"), str(_dec(row.get("discount_value"))), str(_dec(row.get("bundle_price"))),
             row.get("starts_date"), row.get("ends_date"), json.dumps(group), str(_dec(row.get("min_amount"))),
             str(row["max_discount"]) if row.get("max_discount") is not None else None,
             free.get("sku_code") or (gift["sku"] if gift else ""),
             free.get("name_th") or (gift["name"] if gift else ""), str(gift["price"] if gift else 0),
             row.get("starts_at"), row.get("ends_at")),
        )
        saved += 1
    return saved


def _local_day(value) -> date | None:
    """Calendar day of an ERP date value in the shop's local time zone."""
    if value in (None, ""):
        return None
    text = str(value).strip()
    if len(text) == 10:
        return date.fromisoformat(text)
    parsed = datetime.fromisoformat(text.replace("Z", "+00:00"))
    return (parsed.astimezone() if parsed.tzinfo else parsed).date()


def active_promotions(db: sqlite3.Connection, today: date | datetime | None = None) -> list[QtyPromotion]:
    moment = today if isinstance(today, datetime) else datetime.combine(today, time.min).astimezone() if today else datetime.now().astimezone()
    day = moment.date()
    # ERP sends date-only columns serialised as UTC midnight (Bangkok 6 Nov arrives as
    # "2026-11-05T17:00:00Z"), so comparing the raw text lost a promotion's last day and
    # started it a day early. Convert to the shop's local date before comparing.
    rows = [row for row in db.execute("SELECT * FROM qty_promotions ORDER BY id").fetchall()
            if (_local_day(row["starts_date"]) is None or _local_day(row["starts_date"]) <= day)
            and (_local_day(row["ends_date"]) is None or _local_day(row["ends_date"]) >= day)]
    result = [QtyPromotion(
        product_id=int(row["product_id"]), promo_type=str(row["promo_type"]), min_qty=_dec(row["min_qty"]),
        free_product_id=row["free_product_id"], free_qty=_dec(row["free_qty"]), discount_type=row["discount_type"],
        discount_value=_dec(row["discount_value"]), bundle_price=_dec(row["bundle_price"]), name=str(row["name"] or ""),
        product_ids=tuple(json.loads(row["product_ids"] or "[]")), min_amount=_dec(row["min_amount"]),
        max_discount=_dec(row["max_discount"]) if row["max_discount"] is not None else None,
        free_sku=row["free_sku"] or "", free_name=row["free_name"] or "", free_price=_dec(row["free_price"]),
        starts_at=row["starts_at"], ends_at=row["ends_at"],
    ) for row in rows]
    return [promotion for promotion in result if promotion_active(promotion, moment)]
