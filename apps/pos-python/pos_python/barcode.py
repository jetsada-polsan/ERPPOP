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


def replace_scale_profiles(db: sqlite3.Connection, profiles: list[dict]) -> None:
    """เก็บกฎที่ ERP ส่งมาทับของเดิมทั้งชุด ไม่ผสมของเก่ากับของใหม่"""
    db.execute("DELETE FROM scale_profiles")
    db.executemany(
        """INSERT INTO scale_profiles
        (code, prefix, plu_length, value_length, value_type, check_digit, total_length, synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))""",
        [
            (
                profile["code"], profile["prefix"], int(profile["plu_length"]),
                int(profile["value_length"]), profile["value_type"],
                profile["check_digit"], int(profile["total_length"]),
            )
            for profile in profiles
        ],
    )


def load_scale_profiles(db: sqlite3.Connection) -> list[sqlite3.Row]:
    """ตัวที่ตรวจ check digit มาก่อน — ป้ายเดียวกันอาจเข้าได้ทั้งสองกฎ
    ถ้าให้กฎที่ไม่ตรวจชนะ ป้ายที่ถูกแก้ตัวเลขจะผ่านไปได้ทั้งที่ควรถูกปฏิเสธ"""
    return db.execute(
        """SELECT * FROM scale_profiles
        ORDER BY CASE WHEN check_digit = 'ean13' THEN 0 ELSE 1 END, total_length DESC"""
    ).fetchall()


def decode_scale_label(db: sqlite3.Connection, code: str) -> ScaleLabel | None:
    """ถอดป้ายตาม profile ที่ ERP กำหนด ไม่เดารูปแบบเอง

    เครื่องชั่งคนละรุ่นออกป้ายคนละแบบ การเดาผิดคือคิดเงินผิดที่หน้าเคาน์เตอร์ทันที
    ไม่มี profile ที่ตรงเลยจะคืน None ให้ผู้เรียกไปหาบาร์โค้ดปกติต่อ
    """
    scanned = code.strip()
    if not scanned.isdigit():
        return None

    for profile in load_scale_profiles(db):
        label = _decode_with(scanned, profile)
        if label:
            return label
    return None


def _decode_with(scanned: str, profile: sqlite3.Row) -> ScaleLabel | None:
    if len(scanned) != profile["total_length"] or not scanned.startswith(profile["prefix"]):
        return None

    plu = scanned[: profile["plu_length"]]
    raw_value = scanned[profile["plu_length"] : profile["plu_length"] + profile["value_length"]]
    if not plu.isdigit() or not raw_value.isdigit():
        return None

    # 800-839 เป็นรหัสประเทศ EAN ของอิตาลีด้วย การตรวจ check digit จึงเป็นตัวกัน
    # ไม่ให้สินค้านำเข้าถูกอ่านเป็นป้ายชั่ง และกันการแก้ PLU บนป้ายที่พิมพ์แล้ว
    if profile["check_digit"] == "ean13":
        body = scanned[:-1]
        if ean13_check_digit(body) != int(scanned[-1]):
            return None

    value = Decimal(raw_value)
    return ScaleLabel(
        plu=plu,
        total_price=value / Decimal("100") if profile["value_type"] == "price" else value,
    )


def scale_cart_line(db: sqlite3.Connection, code: str) -> CartLine:
    """Turn a one-time scale label into a priced cart line; the raw scan remains in source_barcode."""
    if not load_scale_profiles(db):
        # แยกให้ชัดจาก "ป้ายผิด" เพราะทางแก้คนละเรื่องกันสิ้นเชิง
        raise ValueError("เครื่องนี้ยังไม่ได้รับรูปแบบป้ายเครื่องชั่งจาก ERP — sync ก่อนขายสินค้าชั่ง")
    label = decode_scale_label(db, code)
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
        barcode=label.plu, source_barcode=code.strip(), barcode_type="SCALE_WEIGHT", price_version="scale-label",
    )
