from __future__ import annotations

import json
import sqlite3
from typing import Protocol

from .services import now


class PosApi(Protocol):
    def post(self, path: str, payload: dict, *, idempotency_key: str | None = None) -> dict: ...


class SyncService:
    def __init__(self, connection: sqlite3.Connection, api: PosApi):
        self.db = connection
        self.api = api

    def sync_pending_sales(self) -> dict[str, int]:
        rows = self.db.execute("SELECT aggregate_uuid FROM sync_outbox WHERE aggregate_type = 'sale' AND status IN ('pending', 'failed') ORDER BY id").fetchall()
        result = {"synced": 0, "failed": 0}
        for row in rows:
            try:
                self.sync_sale(row["aggregate_uuid"])
                result["synced"] += 1
            except RuntimeError:
                result["failed"] += 1
        return result

    def sync_sale(self, sale_uuid: str) -> None:
        sale = self.db.execute(
            """SELECT s.*, sh.server_id AS server_shift_id
            FROM sales s JOIN shifts sh ON sh.id = s.shift_id WHERE s.sale_uuid = ?""", (sale_uuid,)
        ).fetchone()
        if not sale:
            raise RuntimeError("ไม่พบบิล local สำหรับ sync")
        if not sale["server_shift_id"]:
            return self._failed(sale_uuid, "ยังไม่ได้ผูกกะ local กับ server_shift_id: ต้องเปิดกะออนไลน์ก่อนขาย offline")
        lines = self.db.execute(
            """SELECT i.*, p.server_id AS server_product_id FROM sale_items i
            JOIN products p ON p.id = i.product_id WHERE i.sale_id = ? ORDER BY i.id""", (sale["id"],)
        ).fetchall()
        if any(not item["server_product_id"] for item in lines):
            return self._failed(sale_uuid, "มีสินค้า local ที่ยังไม่มี server_id: ต้อง sync catalog ก่อน")
        payment = self.db.execute("SELECT method, amount, reference FROM payments WHERE sale_id = ? ORDER BY id LIMIT 1", (sale["id"],)).fetchone()
        if not payment:
            return self._failed(sale_uuid, "ไม่พบข้อมูลชำระเงิน")
        payload = {
            "branch_id": sale["branch_id"], "shift_id": sale["server_shift_id"], "cashier_id": sale["cashier_id"],
            "method": payment["method"], "payment_ref": payment["reference"],
            "cash_received": payment["amount"] if payment["method"] == "cash" else None,
            "items": [{
                "product_id": item["server_product_id"], "qty": item["qty"], "unit_price": item["unit_price"],
                # Server re-parses the raw one-time scale label, protecting price/quantity at both ends.
                "barcode": item["source_barcode"] or item["barcode"], "barcode_type": item["barcode_type"],
            } for item in lines],
        }
        try:
            response = self.api.post("/api/pos/checkout", payload, idempotency_key=sale_uuid)
        except Exception as error:
            return self._failed(sale_uuid, str(error))
        if not response.get("success", False):
            return self._failed(sale_uuid, response.get("message", "server rejected sale"))
        with self.db:
            self.db.execute("UPDATE sales SET sync_status = 'synced' WHERE sale_uuid = ?", (sale_uuid,))
            self.db.execute("UPDATE sync_outbox SET status = 'synced', synced_at = ?, attempts = attempts + 1, last_error = NULL WHERE aggregate_uuid = ?", (now(), sale_uuid))
            self.db.execute("INSERT INTO sync_logs (direction, status, message, created_at) VALUES ('up', 'synced', ?, ?)", (f"sale {sale_uuid}", now()))

    def _failed(self, sale_uuid: str, message: str) -> None:
        with self.db:
            self.db.execute("UPDATE sync_outbox SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE aggregate_uuid = ?", (message[:1000], sale_uuid))
            self.db.execute("INSERT INTO sync_logs (direction, status, message, created_at) VALUES ('up', 'failed', ?, ?)", (f"sale {sale_uuid}: {message}"[:1000], now()))
        raise RuntimeError(message)
