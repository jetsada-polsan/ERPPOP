"""แกนของบิลที่กำลังขาย — แยกจาก Qt เพื่อให้ทดสอบได้โดยไม่ต้องเปิดหน้าจอ

หน้าตาแบบ Odoo คือมีบิลอยู่ซ้าย ตารางสินค้าอยู่ขวา และ numpad ที่แก้ค่าของ
"บรรทัดที่เลือกอยู่" ตามโหมดที่เลือก (จำนวน / ราคา / ส่วนลด) ตรรกะพวกนี้คือที่ที่
ตัวเลขเงินเกิดขึ้นจริง จึงต้องอยู่ในที่ที่เขียนเทสต์ครอบได้ ไม่ใช่ผูกอยู่กับปุ่ม
"""
from __future__ import annotations

import sqlite3
import json
from dataclasses import asdict
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation

from .promotions import QtyPromotion, promotion_discounts, promotion_active
from .services import CartLine, money, vat_from_inclusive

QTY = "qty"
PRICE = "price"
DISCOUNT = "discount"
NUMPAD_MODES = (QTY, PRICE, DISCOUNT)

ALL_CATEGORIES = "ทั้งหมด"


@dataclass
class OrderLine:
    product_id: int
    name: str
    unit_name: str
    qty: Decimal
    unit_price: Decimal
    discount: Decimal = Decimal("0")
    is_vat: bool = True
    barcode: str | None = None
    source_barcode: str | None = None
    barcode_type: str = "CUSTOM"
    price_version: str | None = None
    # ป้ายเครื่องชั่งหนึ่งใบคือของหนึ่งถุง แก้จำนวนทีหลังไม่ได้เพราะน้ำหนักมาจากป้าย
    locked_qty: bool = False
    # ราคาตั้งตอนหยิบสินค้า ERP ตรวจบิลจากราคานี้ ส่วนต่างจากราคาที่แก้คือส่วนลดที่ต้องมีคนอนุมัติ
    list_price: Decimal | None = None

    def __post_init__(self) -> None:
        if self.list_price is None:
            self.list_price = self.unit_price

    @property
    def total(self) -> Decimal:
        return money(self.qty * self.unit_price - self.discount)


@dataclass
class Order:
    lines: list[OrderLine] = field(default_factory=list)
    selected_index: int | None = None
    mode: str = QTY
    _entry: str = ""
    # คนที่อนุมัติให้แก้ราคา/ให้ส่วนลดในบิลนี้ — ล้างทุกครั้งที่เริ่มบิลใหม่
    adjustment_approved_by: str | None = None
    # โปรซื้อครบจำนวนที่ใช้ได้วันนี้ (โหลดจากเครื่อง) ERP หักเองทุกบิล เครื่องจึงต้องหักให้ตรง
    promotions: list[QtyPromotion] = field(default_factory=list)

    bill_discount: Decimal = Decimal("0")

    # ---------- การเพิ่มสินค้า ----------

    def add_product(self, line: OrderLine) -> None:
        """สินค้าเดิมราคาเดิมรวมบรรทัดกัน — ยกเว้นของที่ล็อกจำนวนไว้

        ป้ายเครื่องชั่งต้องแยกบรรทัดเสมอ เพราะแต่ละใบคือถุงจริงคนละถุง
        รวมกันแล้วจะตรวจย้อนกลับไม่ได้ว่ายอดมาจากป้ายใบไหน
        """
        if not line.locked_qty:
            for index, existing in enumerate(self.lines):
                if (
                    existing.product_id == line.product_id
                    and existing.unit_price == line.unit_price
                    and existing.barcode == line.barcode
                    and existing.source_barcode == line.source_barcode
                    and existing.unit_name == line.unit_name
                    and not existing.locked_qty
                ):
                    existing.qty += line.qty
                    self.select(index)
                    return

        self.lines.append(line)
        self.select(len(self.lines) - 1)

    def select(self, index: int | None) -> None:
        self._entry = ""
        self.selected_index = index if index is not None and 0 <= index < len(self.lines) else None

    @property
    def selected(self) -> OrderLine | None:
        return self.lines[self.selected_index] if self.selected_index is not None else None

    def remove_selected(self) -> None:
        if self.selected_index is None:
            return
        self.lines.pop(self.selected_index)
        self.select(len(self.lines) - 1 if self.lines else None)

    def clear(self) -> None:
        self.lines = []
        self.bill_discount = Decimal("0")
        self.adjustment_approved_by = None
        self.mode = QTY
        self.select(None)

    # ---------- numpad ----------

    def set_mode(self, mode: str) -> None:
        if mode not in NUMPAD_MODES:
            raise ValueError(f"โหมดไม่ถูกต้อง: {mode}")
        self.mode = mode
        self._entry = ""

    def press(self, key: str) -> None:
        """รับปุ่มจาก numpad — ตัวเลข จุด ลบ และสลับเครื่องหมาย"""
        line = self.selected
        if line is None:
            return

        if key == "backspace":
            self._entry = self._entry[:-1]
            self._apply(line, self._entry or "0")
            return

        if key == "+/-":
            # ยอดติดลบ ERP ไม่รับ (ดู _apply) จึงไม่มีปุ่มสลับเครื่องหมายแล้ว
            return

        if key == "." and "." in self._entry:
            return
        if key != "." and not key.isdigit():
            return

        self._entry += key
        self._apply(line, self._entry)

    def _apply(self, line: OrderLine, raw: str) -> None:
        try:
            value = Decimal(raw or "0")
        except InvalidOperation:
            return
        if value < 0:
            # ERP รับเฉพาะบิลขายที่ยอดเป็นบวก บรรทัดติดลบจะทำให้บิลส่งไม่ขึ้นตลอดไป
            # การคืนสินค้าต้องทำเป็นใบรับคืนแยก ไม่ใช่บิลขายติดลบ
            return

        if self.mode == QTY:
            if line.locked_qty:
                # แก้จำนวนของป้ายชั่งไม่ได้ น้ำหนักมาจากป้ายที่พิมพ์มาแล้ว
                return
            line.qty = value
        elif line.locked_qty:
            # ERP คิดราคาป้ายชั่งใหม่จากป้ายเอง ราคาหรือส่วนลดที่แก้ในเครื่องจะไม่ตรงกับ ERP
            return
        elif self.mode == PRICE:
            line.unit_price = value
        else:
            line.discount = value

    @property
    def entry(self) -> str:
        return self._entry

    # ---------- ยอดรวม ----------

    def pricing(self):
        promotions = [p for p in self.promotions if promotion_active(p)]
        # Append gifts with their catalog price for the ERP calculation, then
        # persist/submit them at zero. Never merge them with paid cart rows.
        gifts = []
        for p in promotions:
            if p.promo_type != "free_item" or not p.free_product_id:
                continue
            trigger = next((line for line in self.lines if line.product_id == p.product_id), None)
            qty = (trigger.qty // p.min_qty) * p.free_qty if trigger and p.min_qty > 0 else Decimal("0")
            if qty > 0:
                gifts.append((p, qty))
        def gift_lines(entries):
            return [OrderLine(p.free_product_id, p.free_name or "ของแถม", "ชิ้น", qty, p.free_price) for p, qty in entries]
        working = self.lines + gift_lines(gifts)
        base_discount = sum(promotion_discounts(working, [p for p in promotions if not p.promo_type.startswith("bill_")], rounded=False).values(), Decimal("0"))
        paid = sum((line.qty * line.list_price for line in working), Decimal("0")) - base_discount
        for p in promotions:
            if p.promo_type == "bill_free_item":
                gift = next((line for line in working if line.product_id == p.free_product_id), None)
                if gift:
                    paid -= min(gift.qty, p.free_qty) * gift.list_price
        for p in promotions:
            if p.promo_type == "bill_free_item" and p.free_product_id and paid + Decimal("0.0001") >= p.min_amount and p.free_qty > 0:
                gifts.append((p, p.free_qty))
        working = self.lines + gift_lines(gifts)
        breakdown = []
        discounts = promotion_discounts(working, promotions, rounded=False, breakdown=breakdown)
        gross = sum((line.qty * line.list_price for line in working), Decimal("0"))
        expected = money(gross - sum(discounts.values(), Decimal("0")))
        listed = sum((line.qty * line.list_price for line in self.lines), Decimal("0"))
        promo = money(min(listed, max(Decimal("0"), listed - expected)))
        # Proportionate allocation keeps every line nonnegative, including mix
        # discounts larger than the first matching row and multiple promotions.
        available = [max(Decimal("0"), line.total) for line in self.lines]
        base = sum(available, Decimal("0"))
        promo = min(promo, base)
        manual = money(min(max(Decimal("0"), self.bill_discount), max(Decimal("0"), base - promo)))
        def allocate(amount, weights):
            total = sum(weights, Decimal("0"))
            remaining = amount
            result = {}
            for i, weight in enumerate(weights):
                share = min(weight, remaining, money(amount * weight / total)) if total else Decimal("0")
                result[i] = share
                remaining -= share
            # Cent rounding remainder goes to rows with capacity.
            for i, weight in enumerate(weights):
                extra = min(weight - result[i], remaining)
                result[i] += extra
                remaining -= extra
            return result
        allocation = allocate(promo, available)
        manual_allocation = allocate(manual, [v - allocation[i] for i, v in enumerate(available)])
        return promo, manual, allocation, manual_allocation, gifts, breakdown

    def promotion_by_line(self) -> dict[int, Decimal]:
        return self.pricing()[2]

    def promotion_total(self) -> Decimal:
        return self.pricing()[0]

    def manual_bill_discount(self) -> Decimal:
        return self.pricing()[1]

    def free_items(self) -> list[OrderLine]:
        return [OrderLine(p.free_product_id, f"{p.free_name or 'ของแถม'} · {p.name}", "ชิ้น", qty, Decimal("0"), list_price=Decimal("0")) for p, qty in self.pricing()[4]]

    def subtotal(self) -> Decimal:
        return money(sum((money(line.qty * line.unit_price) for line in self.lines), Decimal("0")))

    def discount_total(self) -> Decimal:
        manual = money(sum((money(line.discount) for line in self.lines), Decimal("0")))
        return money(manual + self.promotion_total() + self.manual_bill_discount())

    def grand_total(self) -> Decimal:
        return money(self.subtotal() - self.discount_total())

    def vat_total(self, rate: Decimal) -> Decimal:
        return vat_from_inclusive(money(sum((line.qty * line.unit_price - line.discount - line.promo_discount
            for line, original in zip(self.to_cart_lines(), self.lines) if original.is_vat), Decimal("0"))), rate)

    def adjustment_total(self) -> Decimal:
        listed = money(sum((money(line.qty * line.list_price) for line in self.lines), Decimal("0")))
        return money(listed - self.grand_total() - self.promotion_total())

    def snapshot(self) -> str:
        return json.dumps({"lines": [asdict(line) for line in self.lines],
            "bill_discount": str(self.bill_discount), "adjustment_approved_by": self.adjustment_approved_by}, default=str, ensure_ascii=False)

    @classmethod
    def restore(cls, raw: str):
        data = json.loads(raw)
        lines = []
        for values in data.get("lines", []):
            for key in ("qty", "unit_price", "discount", "list_price"):
                if values.get(key) is not None:
                    values[key] = Decimal(values[key])
            lines.append(OrderLine(**values))
        return cls(lines=lines, bill_discount=Decimal(data.get("bill_discount", "0")),
                   adjustment_approved_by=data.get("adjustment_approved_by"))

    def change_for(self, paid: Decimal) -> Decimal:
        return money(max(Decimal("0"), money(paid) - self.grand_total()))

    def to_cart_lines(self) -> list[CartLine]:
        _, _, promo, manual, _, _ = self.pricing()
        return [CartLine(
            product_id=line.product_id, qty=line.qty, unit_price=line.unit_price,
            discount=line.discount + manual.get(index, Decimal("0")), barcode=line.barcode, source_barcode=line.source_barcode,
            barcode_type=line.barcode_type, price_version=line.price_version, list_price=line.list_price,
            promo_discount=promo.get(index, Decimal("0")),
        ) for index, line in enumerate(self.lines)] + [CartLine(
            product_id=line.product_id, qty=line.qty, unit_price=Decimal("0"), list_price=Decimal("0"))
            for line in self.free_items()]


# ---------- ตารางสินค้าฝั่งขวา ----------


def categories(db: sqlite3.Connection) -> list[str]:
    """หมวดที่มีสินค้าอยู่จริง — ไม่โชว์หมวดว่างให้กดแล้วเจอหน้าเปล่า"""
    rows = db.execute(
        """SELECT DISTINCT category_name FROM products
        WHERE active = 1 AND category_name IS NOT NULL AND category_name <> ''
        ORDER BY category_name"""
    ).fetchall()
    return [ALL_CATEGORIES] + [row["category_name"] for row in rows]


def product_grid(db: sqlite3.Connection, *, category: str = ALL_CATEGORIES, search: str = "", limit: int = 60) -> list[sqlite3.Row]:
    """สินค้าที่จะโชว์เป็นการ์ด กรองตามหมวดและคำค้น"""
    sql = "SELECT id, sku, name, unit_name, price, is_vat, category_name FROM products WHERE active = 1"
    params: list[object] = []

    if category and category != ALL_CATEGORIES:
        sql += " AND category_name = ?"
        params.append(category)

    term = search.strip()
    if term:
        sql += " AND (name LIKE ? OR sku LIKE ? OR id IN (SELECT product_id FROM product_barcodes WHERE barcode LIKE ?))"
        params.extend([f"%{term}%", f"%{term}%", f"%{term}%"])

    sql += " ORDER BY name LIMIT ?"
    params.append(limit)

    return db.execute(sql, params).fetchall()
