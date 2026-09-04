from __future__ import annotations

import json
import sqlite3
from typing import Any, Protocol

from .services import money, now


class PosApi(Protocol):
    def post(self, path: str, payload: dict, *, idempotency_key: str | None = None) -> dict: ...


class SyncService:
    def __init__(self, connection: sqlite3.Connection, api: PosApi):
        self.db = connection
        self.api = api

    def sync_pending_sales(self) -> dict[str, Any]:
        # A locally opened shift must be created on ERP before any sale that
        # belongs to it.  This is the dependency that makes offline selling
        # recoverable after reconnecting.
        result = {"synced": 0, "failed": 0}
        for row in self.db.execute(
            """SELECT aggregate_uuid FROM sync_outbox
               WHERE aggregate_type = 'shift_open' AND status IN ('pending', 'failed')
               ORDER BY priority ASC, created_at ASC, id ASC"""
        ).fetchall():
            try:
                self.sync_shift_open(row["aggregate_uuid"])
                result["synced"] += 1
            except RuntimeError:
                result["failed"] += 1

        rows = self.db.execute(
            """SELECT aggregate_type, aggregate_uuid, depends_on_uuid FROM sync_outbox
               WHERE aggregate_type IN ('sale', 'sale_void') AND status IN ('pending', 'failed')
               ORDER BY priority ASC, created_at ASC, id ASC"""
        ).fetchall()
        for row in rows:
            if row["depends_on_uuid"]:
                dependency = self.db.execute(
                    "SELECT status FROM sync_outbox WHERE aggregate_uuid = ?", (row["depends_on_uuid"],)
                ).fetchone()
                if not dependency or dependency["status"] != "synced":
                    continue
            try:
                if row["aggregate_type"] == "sale":
                    self.sync_sale(row["aggregate_uuid"])
                else:
                    self.sync_void(row["aggregate_uuid"])
                result["synced"] += 1
            except RuntimeError:
                result["failed"] += 1
        for row in self.db.execute(
            """SELECT aggregate_uuid FROM sync_outbox
               WHERE aggregate_type IN ('cash_movement', 'shift_close')
                 AND status IN ('pending', 'failed')
               ORDER BY priority ASC, created_at ASC, id ASC"""
        ).fetchall():
            try:
                if row["aggregate_uuid"].startswith("cash:"):
                    self.sync_cash_movement(row["aggregate_uuid"])
                else:
                    self.sync_shift_close(row["aggregate_uuid"])
                result["synced"] += 1
            except RuntimeError:
                result["failed"] += 1
        auth = self.sync_auth_events()
        self._mark_outbox_state("sales_outbox", result)
        self._mark_outbox_state("auth_events", auth)
        return {"synced": result["synced"] + auth["synced"], "failed": result["failed"] + auth["failed"]}

    def _mark_outbox_state(self, entity: str, result: dict[str, int]) -> None:
        status = "failed" if result["failed"] else "synced"
        timestamp = now()
        self.db.execute(
            """INSERT INTO sync_state
               (entity, status, last_success_at, last_error, item_count, updated_at)
               VALUES (?, ?, ?, ?, ?, ?)
               ON CONFLICT(entity) DO UPDATE SET
                 status = excluded.status,
                 last_success_at = CASE WHEN excluded.status = 'synced' THEN excluded.last_success_at ELSE sync_state.last_success_at END,
                 last_error = excluded.last_error,
                 item_count = excluded.item_count,
                 updated_at = excluded.updated_at""",
            (entity, status, timestamp if status == "synced" else None,
             f"{result['failed']} รายการยังส่งไม่สำเร็จ" if result["failed"] else None,
             result["synced"], timestamp),
        )
        self.db.commit()

    def sync_auth_events(self) -> dict[str, int]:
        """Upload offline login/recovery audit events without blocking sales sync.

        The event UUID is the idempotency key.  A reconnect may retry the same
        audit record many times; ERP accepts it once and returns the same result.
        """
        rows = self.db.execute(
            "SELECT * FROM auth_events_outbox WHERE synced = 0 ORDER BY id LIMIT 100"
        ).fetchall()
        result = {"synced": 0, "failed": 0}
        for row in rows:
            payload = {
                "event_uuid": row["event_uuid"],
                "cashier_code": row["cashier_code"],
                "event_type": row["event_type"],
                "success": bool(row["success"]),
                "reason": row["reason"],
                "terminal_code": row["terminal_code"],
                "branch_code": row["branch_code"],
                "occurred_at": row["occurred_at"],
            }
            try:
                response = self.api.post("/api/pos/auth-events", {"events": [payload]}, idempotency_key=row["event_uuid"])
                if not response.get("success", False):
                    raise RuntimeError(response.get("message", "server rejected auth event"))
                with self.db:
                    self.db.execute(
                        "UPDATE auth_events_outbox SET synced = 1, synced_at = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?",
                        (now(), row["id"]),
                    )
                    self.db.execute(
                        "UPDATE sync_outbox SET status = 'synced', synced_at = ?, attempts = attempts + 1, last_error = NULL WHERE aggregate_uuid = ?",
                        (now(), f"auth:{row['event_uuid']}"),
                    )
                result["synced"] += 1
            except Exception as error:
                with self.db:
                    self.db.execute(
                        "UPDATE auth_events_outbox SET attempts = attempts + 1, last_error = ? WHERE id = ?",
                        (str(error)[:1000], row["id"]),
                    )
                    self.db.execute(
                        "UPDATE sync_outbox SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE aggregate_uuid = ?",
                        (str(error)[:1000], f"auth:{row['event_uuid']}"),
                    )
                result["failed"] += 1
        return result

    def sync_shift_open(self, aggregate_uuid: str) -> None:
        row = self.db.execute(
            "SELECT payload FROM sync_outbox WHERE aggregate_uuid = ? AND aggregate_type = 'shift_open'",
            (aggregate_uuid,),
        ).fetchone()
        if not row:
            raise RuntimeError("ไม่พบคิวเปิดกะ")
        try:
            event = json.loads(row["payload"])
            shift_uuid = str(event["shift_uuid"])
            cashier_id = int(event["cashier_server_id"])
        except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
            return self._failed_outbox(aggregate_uuid, f"ข้อมูลเปิดกะไม่ถูกต้อง: {error}")
        if cashier_id <= 0:
            return self._failed_outbox(aggregate_uuid, "แคชเชียร์ยังไม่มี server_id: ต้อง sync รายชื่อก่อน")
        shift = self.db.execute("SELECT * FROM shifts WHERE uuid = ?", (shift_uuid,)).fetchone()
        if not shift:
            return self._failed_outbox(aggregate_uuid, "ไม่พบกะ local สำหรับ sync")
        if shift["server_id"]:
            self._mark_synced(aggregate_uuid)
            return
        try:
            response = self.api.post("/api/pos/shift/open", {
                "branch_id": int(event["branch_id"]), "cashier_id": cashier_id,
                "opening_cash": str(money(shift["opening_cash"])),
            }, idempotency_key=aggregate_uuid)
        except Exception as error:
            return self._failed_outbox(aggregate_uuid, str(error))
        if not response.get("success", False):
            return self._failed_outbox(aggregate_uuid, response.get("message", "เปิดกะไม่สำเร็จ"))
        server_shift_id = (response.get("shift") or {}).get("id")
        if not server_shift_id:
            return self._failed_outbox(aggregate_uuid, "ERP เปิดกะแล้วแต่ไม่คืนเลขกะ")
        with self.db:
            self.db.execute("UPDATE shifts SET server_id = ? WHERE uuid = ?", (int(server_shift_id), shift_uuid))
        self._mark_synced(aggregate_uuid)

    def sync_cash_movement(self, aggregate_uuid: str) -> None:
        row = self.db.execute("SELECT payload FROM sync_outbox WHERE aggregate_uuid = ?", (aggregate_uuid,)).fetchone()
        if not row:
            raise RuntimeError("ไม่พบคิวเงินเข้าออก")
        try:
            movement_uuid = str(json.loads(row["payload"])["movement_uuid"])
        except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
            return self._failed_outbox(aggregate_uuid, f"ข้อมูลเงินเข้าออกไม่ถูกต้อง: {error}")
        movement = self.db.execute(
            """SELECT m.*, s.server_id AS server_shift_id FROM cash_movements m
               JOIN shifts s ON s.id = m.shift_id WHERE m.movement_uuid = ?""", (movement_uuid,)
        ).fetchone()
        if not movement or not movement["server_shift_id"]:
            return self._failed_outbox(aggregate_uuid, "ยังไม่ได้ผูกกะบน ERP")
        try:
            response = self.api.post("/api/pos/shift/cash-movement", {
                "shift_id": int(movement["server_shift_id"]),
                "movement_type": movement["movement_type"], "amount": str(money(movement["amount"])),
                "reference_no": movement["reference_no"], "reason": movement["reason"],
            }, idempotency_key=movement_uuid)
        except Exception as error:
            return self._failed_outbox(aggregate_uuid, str(error))
        if not response.get("success", False):
            return self._failed_outbox(aggregate_uuid, response.get("message", "ซิงก์เงินเข้าออกไม่สำเร็จ"))
        with self.db:
            self.db.execute("UPDATE cash_movements SET sync_status = 'synced' WHERE movement_uuid = ?", (movement_uuid,))
        self._mark_synced(aggregate_uuid)

    def sync_shift_close(self, aggregate_uuid: str) -> None:
        row = self.db.execute("SELECT payload FROM sync_outbox WHERE aggregate_uuid = ?", (aggregate_uuid,)).fetchone()
        if not row:
            raise RuntimeError("ไม่พบคิวปิดกะ")
        try:
            event = json.loads(row["payload"])
            shift = self.db.execute("SELECT * FROM shifts WHERE uuid = ?", (str(event["shift_uuid"]),)).fetchone()
        except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
            return self._failed_outbox(aggregate_uuid, f"ข้อมูลปิดกะไม่ถูกต้อง: {error}")
        if not shift or not shift["server_id"]:
            return self._failed_outbox(aggregate_uuid, "ยังไม่ได้ผูกกะบน ERP")
        try:
            response = self.api.post("/api/pos/shift/close", {
                "shift_id": int(shift["server_id"]), "counted_cash": str(money(event["counted_cash"])),
                "closing_note": event.get("closing_note"),
            }, idempotency_key=aggregate_uuid)
        except Exception as error:
            return self._failed_outbox(aggregate_uuid, str(error))
        if not response.get("success", False):
            return self._failed_outbox(aggregate_uuid, response.get("message", "ปิดกะบน ERP ไม่สำเร็จ"))
        self._mark_synced(aggregate_uuid)

    def _mark_synced(self, aggregate_uuid: str) -> None:
        with self.db:
            self.db.execute(
                "UPDATE sync_outbox SET status = 'synced', synced_at = ?, attempts = attempts + 1, last_error = NULL WHERE aggregate_uuid = ?",
                (now(), aggregate_uuid),
            )

    def _failed_outbox(self, aggregate_uuid: str, message: str) -> None:
        with self.db:
            self.db.execute(
                "UPDATE sync_outbox SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE aggregate_uuid = ?",
                (message[:1000], aggregate_uuid),
            )
        raise RuntimeError(message)

    def sync_sale(self, sale_uuid: str) -> None:
        sale = self.db.execute(
            """SELECT s.*, sh.server_id AS server_shift_id, c.server_id AS server_cashier_id
            FROM sales s
            JOIN shifts sh ON sh.id = s.shift_id
            LEFT JOIN local_cashiers c ON c.id = s.cashier_id
            WHERE s.sale_uuid = ?""", (sale_uuid,)
        ).fetchone()
        if not sale:
            raise RuntimeError("ไม่พบบิล local สำหรับ sync")
        if not sale["server_shift_id"]:
            return self._failed(sale_uuid, "ยังไม่ได้ผูกกะ local กับ server_shift_id: ต้องเปิดกะออนไลน์ก่อนขาย offline")
        # cashier_id ที่ ERP รู้จักคือ Salesman.id ฝั่ง server ห้ามส่ง local id ไปตรง ๆ
        # เพราะ id สองฝั่งไม่รับประกันว่าตรงกัน ยอดขายจะไปลงชื่อคนผิด
        if not sale["server_cashier_id"]:
            return self._failed(sale_uuid, "แคชเชียร์ยังไม่มี server_id: ต้อง sync รายชื่อแคชเชียร์ก่อน")
        lines = self.db.execute(
            """SELECT i.*, p.server_id AS server_product_id FROM sale_items i
            JOIN products p ON p.id = i.product_id WHERE i.sale_id = ? ORDER BY i.id""", (sale["id"],)
        ).fetchall()
        if any(not item["server_product_id"] for item in lines):
            return self._failed(sale_uuid, "มีสินค้า local ที่ยังไม่มี server_id: ต้อง sync catalog ก่อน")
        payment = self.db.execute(
            "SELECT method, amount, reference, confirmed_at FROM payments WHERE sale_id = ? ORDER BY id LIMIT 1",
            (sale["id"],),
        ).fetchone()
        if not payment:
            return self._failed(sale_uuid, "ไม่พบข้อมูลชำระเงิน")
        payload = {
            "branch_id": sale["branch_id"], "shift_id": sale["server_shift_id"], "cashier_id": sale["server_cashier_id"],
            "method": payment["method"], "payment_ref": payment["reference"],
            "payment_confirmed": payment["method"] != "transfer" or bool(payment["confirmed_at"]),
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
        receipt_no = str(response.get("receipt_no") or "").strip()
        if not receipt_no:
            return self._failed(sale_uuid, "ERP ตอบรับการขายแต่ไม่คืน receipt_no")
        with self.db:
            self.db.execute("UPDATE sales SET sync_status = 'synced', server_receipt_no = ? WHERE sale_uuid = ?", (receipt_no, sale_uuid))
            self.db.execute("UPDATE sync_outbox SET status = 'synced', synced_at = ?, attempts = attempts + 1, last_error = NULL WHERE aggregate_uuid = ?", (now(), sale_uuid))
            self.db.execute("INSERT INTO sync_logs (direction, status, message, created_at) VALUES ('up', 'synced', ?, ?)", (f"sale {sale_uuid}", now()))

    def sync_void(self, void_uuid: str) -> None:
        outbox = self.db.execute(
            "SELECT payload FROM sync_outbox WHERE aggregate_uuid = ? AND aggregate_type = 'sale_void'", (void_uuid,)
        ).fetchone()
        if not outbox:
            raise RuntimeError("ไม่พบคิวการยกเลิกบิล")
        try:
            event = json.loads(outbox["payload"])
            sale_uuid = str(event["sale_uuid"])
            reason = str(event["reason"]).strip()
        except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
            return self._failed(void_uuid, f"ข้อมูลคิวยกเลิกไม่ถูกต้อง: {error}")

        sale = self.db.execute(
            "SELECT s.*, sh.server_id AS server_shift_id FROM sales s JOIN shifts sh ON sh.id = s.shift_id WHERE s.sale_uuid = ?",
            (sale_uuid,),
        ).fetchone()
        if not sale:
            return self._failed(void_uuid, "ไม่พบบิล local ที่ต้องยกเลิก")
        if not sale["server_receipt_no"]:
            # offline void อาจเกิดก่อนบิลแรกถูกส่งขึ้น ERP; sync บิลก่อนโดยใช้ idempotency key เดิม
            self.sync_sale(sale_uuid)
            sale = self.db.execute(
                "SELECT s.*, sh.server_id AS server_shift_id FROM sales s JOIN shifts sh ON sh.id = s.shift_id WHERE s.sale_uuid = ?",
                (sale_uuid,),
            ).fetchone()
        if not sale["server_shift_id"]:
            return self._failed(void_uuid, "ยังไม่ได้ผูกกะ local กับ server_shift_id")
        if not sale["server_receipt_no"]:
            return self._failed(void_uuid, "บิลยังไม่มี receipt_no จาก ERP")

        try:
            response = self.api.post("/api/pos/receipt/void", {
                "receipt_no": sale["server_receipt_no"],
                "shift_id": sale["server_shift_id"],
                "reason": reason,
            }, idempotency_key=void_uuid)
        except Exception as error:
            return self._failed(void_uuid, str(error))
        if not response.get("success", False):
            return self._failed(void_uuid, response.get("message", "server rejected void"))
        with self.db:
            self.db.execute("UPDATE sync_outbox SET status = 'synced', synced_at = ?, attempts = attempts + 1, last_error = NULL WHERE aggregate_uuid = ?", (now(), void_uuid))
            self.db.execute("INSERT INTO sync_logs (direction, status, message, created_at) VALUES ('up', 'synced', ?, ?)", (f"void {sale_uuid}", now()))

    def _failed(self, sale_uuid: str, message: str) -> None:
        with self.db:
            self.db.execute("UPDATE sync_outbox SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE aggregate_uuid = ?", (message[:1000], sale_uuid))
            self.db.execute("INSERT INTO sync_logs (direction, status, message, created_at) VALUES ('up', 'failed', ?, ?)", (f"sale {sale_uuid}: {message}"[:1000], now()))
        raise RuntimeError(message)
