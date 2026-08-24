from __future__ import annotations

import hashlib
import json
import sqlite3
import uuid
from dataclasses import dataclass
from datetime import datetime, timezone
from decimal import Decimal, ROUND_HALF_UP


def now() -> str:
    return datetime.now(timezone.utc).isoformat()


def money(value: Decimal | float | str) -> Decimal:
    return Decimal(str(value)).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def pin_hash(pin: str) -> str:
    # Prototype only. Production must use Argon2/bcrypt with a per-user salt.
    return hashlib.sha256(pin.encode("utf-8")).hexdigest()


@dataclass(frozen=True)
class CartLine:
    product_id: int
    qty: Decimal
    unit_price: Decimal
    barcode: str | None = None
    source_barcode: str | None = None
    discount: Decimal = Decimal("0")
    price_version: str | None = None


class PosService:
    def __init__(self, connection: sqlite3.Connection):
        self.db = connection

    def login(self, cashier_code: str, pin: str) -> sqlite3.Row | None:
        row = self.db.execute("SELECT * FROM local_cashiers WHERE code = ? AND active = 1", (cashier_code,)).fetchone()
        return row if row and row["pin_hash"] == pin_hash(pin) else None

    def open_shift(self, branch_id: int, terminal_id: str, cashier_id: int, opening_cash: Decimal) -> int:
        existing = self.db.execute("SELECT id FROM shifts WHERE terminal_id = ? AND status = 'open'", (terminal_id,)).fetchone()
        if existing:
            return int(existing["id"])
        cursor = self.db.execute(
            """INSERT INTO shifts (uuid, branch_id, terminal_id, cashier_id, opened_at, opening_cash, status)
            VALUES (?, ?, ?, ?, ?, ?, 'open')""",
            (str(uuid.uuid4()), branch_id, terminal_id, cashier_id, now(), str(money(opening_cash))),
        )
        self.db.commit()
        return int(cursor.lastrowid)

    def lookup_barcode(self, barcode: str) -> sqlite3.Row | None:
        return self.db.execute(
            """SELECT p.*, b.barcode, b.barcode_type, b.unit_factor, b.price
            FROM product_barcodes b JOIN products p ON p.id = b.product_id
            WHERE b.barcode = ? AND p.active = 1""", (barcode,)
        ).fetchone()

    def checkout(self, *, document_no: str, branch_id: int, terminal_id: str, shift_id: int,
                 cashier_id: int, lines: list[CartLine], payment_method: str, paid_amount: Decimal,
                 sale_uuid: str | None = None) -> int:
        if not lines:
            raise ValueError("ต้องมีสินค้าอย่างน้อยหนึ่งรายการ")
        sale_uuid = sale_uuid or str(uuid.uuid4())
        existing = self.db.execute("SELECT id FROM sales WHERE sale_uuid = ?", (sale_uuid,)).fetchone()
        if existing:
            return int(existing["id"])
        subtotal = sum((money(line.qty * line.unit_price) for line in lines), Decimal("0"))
        discount = sum((money(line.discount) for line in lines), Decimal("0"))
        grand_total = money(subtotal - discount)
        if money(paid_amount) < grand_total:
            raise ValueError("ยอดชำระไม่พอ")
        with self.db:
            cursor = self.db.execute(
                """INSERT INTO sales (sale_uuid, document_no, branch_id, terminal_id, shift_id, cashier_id,
                sale_datetime, subtotal, discount_total, vat_total, grand_total, payment_status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 'paid', ?)""",
                (sale_uuid, document_no, branch_id, terminal_id, shift_id, cashier_id, now(), str(subtotal),
                 str(discount), str(grand_total), now()),
            )
            sale_id = int(cursor.lastrowid)
            for line in lines:
                product = self.db.execute("SELECT name, unit_name FROM products WHERE id = ? AND active = 1", (line.product_id,)).fetchone()
                if not product:
                    raise ValueError(f"ไม่พบสินค้าที่ใช้งานได้ id={line.product_id}")
                line_total = money(line.qty * line.unit_price - line.discount)
                self.db.execute(
                    """INSERT INTO sale_items (sale_id, product_id, barcode, source_barcode, product_name_snapshot,
                    unit_name_snapshot, qty, unit_price, discount, line_total, price_version)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)""",
                    (sale_id, line.product_id, line.barcode, line.source_barcode, product["name"], product["unit_name"],
                     str(line.qty), str(line.unit_price), str(line.discount), str(line_total), line.price_version),
                )
            self.db.execute("INSERT INTO payments (sale_id, method, amount) VALUES (?, ?, ?)", (sale_id, payment_method, str(money(paid_amount))))
            self.db.execute("INSERT INTO print_jobs (sale_id, created_at) VALUES (?, ?)", (sale_id, now()))
            payload = json.dumps({"sale_uuid": sale_uuid, "document_no": document_no, "grand_total": str(grand_total)})
            self.db.execute("INSERT INTO sync_outbox (aggregate_type, aggregate_uuid, payload, created_at) VALUES ('sale', ?, ?, ?)", (sale_uuid, payload, now()))
        return sale_id

    def pending_sync_count(self) -> int:
        return int(self.db.execute("SELECT count(*) FROM sync_outbox WHERE status = 'pending'").fetchone()[0])
