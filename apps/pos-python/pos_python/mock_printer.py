"""ส่งใบเสร็จออกทางไฟล์ — ตัวแทนเครื่องพิมพ์จนกว่าจะต่อของจริง

ใช้ตัวเรนเดอร์เดียวกับที่พรีวิวบนจอ เพราะพรีวิวที่ไม่ตรงกับกระดาษที่ออกมา
คือพรีวิวที่หลอกคนตั้งค่า
"""
from __future__ import annotations

import json
import sqlite3
from pathlib import Path

from .receipt import render_text

COMPANY_SETTING = "company"

FOOTER_SETTING = "receipt_footer"


def company_details(db: sqlite3.Connection) -> dict:
    row = db.execute("SELECT value FROM device_settings WHERE key = ?", (COMPANY_SETTING,)).fetchone()
    if not row:
        return {}
    try:
        details = json.loads(row["value"])
    except json.JSONDecodeError:
        return {}
    return details if isinstance(details, dict) else {}


def receipt_footer(db: sqlite3.Connection) -> str | None:
    row = db.execute("SELECT value FROM device_settings WHERE key = ?", (FOOTER_SETTING,)).fetchone()
    if not row:
        return None
    try:
        footer = json.loads(row["value"])
    except json.JSONDecodeError:
        return None
    return str(footer) if footer else None


def active_paper_width(db: sqlite3.Connection) -> int:
    row = db.execute(
        "SELECT paper_width_mm FROM printer_profiles WHERE active = 1 ORDER BY id DESC LIMIT 1"
    ).fetchone()
    return int(row["paper_width_mm"]) if row else 80


def receipt_for(db: sqlite3.Connection, sale_id: int) -> str:
    return render_text(
        db, sale_id,
        company=company_details(db),
        paper_width_mm=active_paper_width(db),
        footer=receipt_footer(db),
    )


def print_receipt(db: sqlite3.Connection, sale_id: int, receipts_dir: Path) -> Path:
    sale = db.execute("SELECT document_no FROM sales WHERE id = ?", (sale_id,)).fetchone()
    if not sale:
        raise ValueError("ไม่พบบิล")

    receipts_dir.mkdir(parents=True, exist_ok=True)
    output = receipts_dir / f"{sale['document_no']}.txt"
    output.write_text(receipt_for(db, sale_id) + "\n", encoding="utf-8")
    db.execute("UPDATE print_jobs SET status = 'printed', attempts = attempts + 1 WHERE sale_id = ?", (sale_id,))
    db.commit()
    return output
