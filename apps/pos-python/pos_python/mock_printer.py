from __future__ import annotations

from pathlib import Path
from sqlite3 import Connection


def print_receipt(db: Connection, sale_id: int, receipts_dir: Path) -> Path:
    sale = db.execute("SELECT document_no, grand_total FROM sales WHERE id = ?", (sale_id,)).fetchone()
    if not sale:
        raise ValueError("ไม่พบบิล")
    items = db.execute("SELECT product_name_snapshot, qty, line_total FROM sale_items WHERE sale_id = ?", (sale_id,)).fetchall()
    receipts_dir.mkdir(parents=True, exist_ok=True)
    output = receipts_dir / f"{sale['document_no']}.txt"
    body = ["POPSTAR POS - MOCK RECEIPT", sale["document_no"], "-" * 32]
    body += [f"{item['product_name_snapshot']} x{item['qty']} = {item['line_total']}" for item in items]
    body += ["-" * 32, f"TOTAL {sale['grand_total']}"]
    output.write_text("\n".join(body) + "\n", encoding="utf-8")
    db.execute("UPDATE print_jobs SET status = 'printed', attempts = attempts + 1 WHERE sale_id = ?", (sale_id,))
    db.commit()
    return output
