"""แกนของบิลที่กำลังขาย — แยกจาก Qt เพื่อให้ทดสอบได้โดยไม่ต้องเปิดหน้าจอ

หน้าตาแบบ Odoo คือมีบิลอยู่ซ้าย ตารางสินค้าอยู่ขวา และ numpad ที่แก้ค่าของ
"บรรทัดที่เลือกอยู่" ตามโหมดที่เลือก (จำนวน / ราคา / ส่วนลด) ตรรกะพวกนี้คือที่ที่
ตัวเลขเงินเกิดขึ้นจริง จึงต้องอยู่ในที่ที่เขียนเทสต์ครอบได้ ไม่ใช่ผูกอยู่กับปุ่ม
"""
from __future__ import annotations

import sqlite3
from dataclasses import dataclass, field
from decimal import Decimal, InvalidOperation

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

    def subtotal(self) -> Decimal:
        return money(sum((money(line.qty * line.unit_price) for line in self.lines), Decimal("0")))

    def discount_total(self) -> Decimal:
        return money(sum((money(line.discount) for line in self.lines), Decimal("0")))

    def grand_total(self) -> Decimal:
        return money(self.subtotal() - self.discount_total())

    def vat_total(self, rate: Decimal) -> Decimal:
        vatable = sum((line.total for line in self.lines if line.is_vat), Decimal("0"))
        return vat_from_inclusive(money(vatable), rate)

    def adjustment_total(self) -> Decimal:
        """ส่วนลดทั้งหมดที่คนกดเอง (ลดราคา + ส่วนลดท้ายบรรทัด) เทียบกับราคาตั้ง"""
        listed = money(sum((money(line.qty * line.list_price) for line in self.lines), Decimal("0")))
        return money(listed - self.grand_total())

    def change_for(self, paid: Decimal) -> Decimal:
        return money(max(Decimal("0"), money(paid) - self.grand_total()))

    def to_cart_lines(self) -> list[CartLine]:
        return [
            CartLine(
                product_id=line.product_id, qty=line.qty, unit_price=line.unit_price,
                discount=line.discount, barcode=line.barcode, source_barcode=line.source_barcode,
                barcode_type=line.barcode_type, price_version=line.price_version, list_price=line.list_price,
            )
            for line in self.lines
        ]


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
        sql += " AND (name LIKE ? OR sku LIKE ?)"
        params.extend([f"%{term}%", f"%{term}%"])

    sql += " ORDER BY name LIMIT ?"
    params.append(limit)

    return db.execute(sql, params).fetchall()
