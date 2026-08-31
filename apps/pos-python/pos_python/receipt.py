"""เรนเดอร์ใบเสร็จ 80mm เป็นข้อความ — ตัวเดียวกับที่พรีวิวบนจอและที่ส่งเข้าเครื่องพิมพ์

แยกออกมาเป็นข้อความล้วนเพราะสิ่งที่ลูกค้าถือกลับบ้านต้องทดสอบได้ ไม่ใช่ตรวจด้วยตา
ทีละใบ และเพราะพรีวิวกับของที่พิมพ์จริงต้องมาจากที่เดียวกัน ไม่งั้นสองอย่างจะค่อย ๆ
ต่างกันโดยไม่มีใครสังเกต
"""
from __future__ import annotations

import sqlite3
from decimal import Decimal

from .services import money
from .promptpay import compact_qr_lines

# สระบนล่างและวรรณยุกต์ไทยไม่กินความกว้างตอนพิมพ์ นับด้วย len() ตรง ๆ
# แล้วคอลัมน์ขวาจะเยื้องทีละนิดจนตัวเลขไม่ตรงแถวกัน
THAI_COMBINING = set(
    "ัิีึืฺุู"
    "็่้๊๋์ํ๎"
)

PAPER_WIDTH_CHARS = {58: 32, 80: 42}
DEFAULT_WIDTH = 42


def display_width(text: str) -> int:
    return sum(0 if character in THAI_COMBINING else 1 for character in text)


def width_for(paper_width_mm: int | None) -> int:
    return PAPER_WIDTH_CHARS.get(int(paper_width_mm or 0), DEFAULT_WIDTH)


def centre(text: str, width: int) -> str:
    padding = max(0, width - display_width(text))
    return " " * (padding // 2) + text


def columns(left: str, right: str, width: int) -> str:
    """ซ้ายชิดซ้าย ขวาชิดขวา — ถ้าชนกันให้ตัดฝั่งซ้าย ตัวเงินต้องอ่านออกเสมอ"""
    space = width - display_width(right) - 1
    while display_width(left) > space and left:
        left = left[:-1]
    gap = max(1, width - display_width(left) - display_width(right))
    return left + " " * gap + right


def rule(width: int, character: str = "-") -> str:
    return character * width


def quantity_text(qty: Decimal, unit_name: str, weighed: bool) -> str:
    """ของชั่งบอกน้ำหนักพร้อมหน่วย ของนับบอกเป็น x2 แบบที่คนอ่านใบเสร็จคุ้น"""
    if weighed:
        return f"{qty:,.3f}".rstrip("0").rstrip(".") + f" {unit_name}"
    whole = f"{qty:,.3f}".rstrip("0").rstrip(".")
    return f"x{whole}"


def render(db: sqlite3.Connection, sale_id: int, *, company: dict | None = None,
           paper_width_mm: int | None = 80, footer: str | None = None) -> list[str]:
    sale = db.execute(
        """SELECT document_no, sale_datetime, grand_total, subtotal, discount_total, vat_total,
        cashier_id, is_void, void_reason FROM sales WHERE id = ?""",
        (sale_id,),
    ).fetchone()
    if not sale:
        raise ValueError("ไม่พบบิล")

    items = db.execute(
        """SELECT product_name_snapshot, unit_name_snapshot, qty, line_total, barcode_type
        FROM sale_items WHERE sale_id = ? ORDER BY id""",
        (sale_id,),
    ).fetchall()
    payment = db.execute(
        """SELECT method, amount, change_amount, reference, qr_payload
           FROM payments WHERE sale_id = ? ORDER BY id LIMIT 1""", (sale_id,)
    ).fetchone()
    cashier = db.execute("SELECT code FROM local_cashiers WHERE id = ?", (sale["cashier_id"],)).fetchone()

    company = company or {}
    width = width_for(paper_width_mm)
    lines: list[str] = []

    lines.append(centre(company.get("name", "POPSTAR"), width))
    if company.get("branch") or company.get("phone"):
        lines.append(centre(" · ".join(filter(None, [company.get("branch"), company.get("phone")])), width))
    if company.get("tax_id"):
        lines.append(centre(f"เลขประจำตัวผู้เสียภาษี {company['tax_id']}", width))
    lines.append(rule(width))

    lines.append(centre("ใบเสร็จรับเงิน / ใบกำกับภาษีอย่างย่อ", width))
    lines.append(columns("เลขที่", sale["document_no"], width))
    lines.append(columns("วันที่", str(sale["sale_datetime"])[:16].replace("T", " "), width))
    if cashier:
        lines.append(columns("แคชเชียร์", cashier["code"], width))
    lines.append(rule(width))

    for item in items:
        weighed = item["barcode_type"] == "SCALE_WEIGHT"
        label = f"{item['product_name_snapshot']} {quantity_text(Decimal(str(item['qty'])), item['unit_name_snapshot'] or '', weighed)}"
        lines.append(columns(label, f"{money(item['line_total']):,.2f}", width))

    lines.append(rule(width))
    if Decimal(str(sale["discount_total"])) > 0:
        lines.append(columns("ส่วนลด", f"-{money(sale['discount_total']):,.2f}", width))
    lines.append(columns("รวมสุทธิ", f"{money(sale['grand_total']):,.2f}", width))

    # ใบกำกับภาษีอย่างย่อต้องแสดงจำนวนภาษีมูลค่าเพิ่ม ไม่ใช่แค่ยอดรวม
    vat = money(sale["vat_total"])
    lines.append(columns("มูลค่าก่อน VAT", f"{money(sale['grand_total']) - vat:,.2f}", width))
    lines.append(columns("ภาษีมูลค่าเพิ่ม", f"{vat:,.2f}", width))

    if payment:
        received = money(payment["amount"])
        change = money(payment["change_amount"] or 0)
        lines.append(columns("เงินสดรับ" if payment["method"] == "cash" else "โอนเงิน / QR", f"{received:,.2f}", width))
        if change > 0:
            lines.append(columns("เงินทอน", f"{change:,.2f}", width))
        if payment["method"] == "transfer" and payment["qr_payload"]:
            lines.append(rule(width))
            lines.append(centre("สแกน QR เพื่อชำระยอดนี้", width))
            for qr_line in compact_qr_lines(str(payment["qr_payload"])):
                lines.append(centre(qr_line, width))
            if payment["reference"]:
                lines.append(centre(f"บัญชี {payment['reference']}", width))

    if sale["is_void"]:
        lines.append(rule(width, "="))
        lines.append(centre("*** บิลนี้ถูกยกเลิก ***", width))
        if sale["void_reason"]:
            lines.append(centre(str(sale["void_reason"]), width))

    lines.append("")
    lines.append(centre(footer or "ขอบคุณที่ใช้บริการ", width))
    return lines


def render_text(db: sqlite3.Connection, sale_id: int, **kwargs) -> str:
    return "\n".join(render(db, sale_id, **kwargs))
