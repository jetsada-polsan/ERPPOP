"""ดึงข้อมูลจาก ERP ลงเครื่อง (sync ลง) และเปิดกะออนไลน์

SyncService ทำ "sync ขึ้น" (ส่งบิล/ยกเลิก) ไฟล์นี้ทำอีกครึ่งที่ขาด: bootstrap
ด้วย ping, ดึง catalog กับรายชื่อแคชเชียร์มาเก็บ พร้อม server_id เพื่อให้ sync ขึ้น
อ้าง id ฝั่ง ERP ได้ถูกตัว และเปิดกะออนไลน์เพื่อผูก server_shift_id ก่อนขาย

ทุกอย่างคุยผ่าน Laravel API เท่านั้น (ตาม PosApi protocol) ไม่แตะ PostgreSQL ตรง
เก็บลง SQLite แบบ upsert ตาม server_id ไม่ลบทั้งตารางทับ เพราะบิลเก่ายังอ้าง
products.id กับ shifts.id เดิมอยู่ ลบแล้วประวัติขายที่ยังไม่ sync จะพังตาม
"""
from __future__ import annotations

import json
import sqlite3
from typing import Any, Protocol

from .barcode import replace_scale_profiles
from .services import money, now
from .time_service import TimeService


class PosApiDown(Protocol):
    def get(self, path: str) -> dict: ...
    def post(self, path: str, payload: dict, *, idempotency_key: str | None = None) -> dict: ...


# ค่าจาก ping ที่ต้องแคชไว้ให้ทำงานต่อได้ตอนออฟไลน์ (คีย์ device_settings -> ตำแหน่งใน payload)
_PING_SETTINGS = {
    "branch_id": ("branch_id",),
    "branch_name": ("branch_name",),
    "terminal_code": ("device", "terminal_code"),
    "device_user_id": ("device", "user_id"),
    "vat_rate": ("vat_rate",),
    "cashier_login_mode": ("cashier_login_mode",),
    "qr_payment": ("qr_payment",),
    "company": ("company",),
    "receipt_template": ("receipt_template",),
    "hardware_profile": ("hardware_profile",),
    "pos_layout": ("pos_layout",),
}


class ProvisioningService:
    def __init__(self, connection: sqlite3.Connection, api: PosApiDown):
        self.db = connection
        self.api = api

    # ── bootstrap ────────────────────────────────────────────────
    def ping(self) -> dict[str, Any]:
        """ตัวชี้ขาดว่า 'เชื่อม ERP ได้ไหม' + แคชโปรไฟล์เครื่องไว้ใช้ตอนออฟไลน์"""
        profile = self.api.get("/api/pos/ping")
        if not profile.get("success", False):
            raise RuntimeError(profile.get("message", "ping ไม่สำเร็จ"))
        with self.db:
            if profile.get("server_time"):
                TimeService(self.db).update_offset(str(profile["server_time"]))
            for key, path in _PING_SETTINGS.items():
                value: Any = profile
                for step in path:
                    value = value.get(step) if isinstance(value, dict) else None
                if value is not None:
                    self._put_setting(key, value)
            profiles = profile.get("scale_profiles") or []
            if profiles:
                # กฎอ่านป้ายเครื่องชั่งมาจาก ERP ที่เดียว เครื่องขายไม่เดารูปแบบเอง
                replace_scale_profiles(self.db, profiles)
        return profile

    # ── catalog ──────────────────────────────────────────────────
    def pull_catalog(self, branch_id: int) -> dict[str, int]:
        """ดึงสินค้าทั้งหมดมาเก็บ upsert ตาม server_id + บาร์โค้ด + ราคาปัจจุบัน"""
        response = self.api.get(f"/api/pos/products?branch_id={int(branch_id)}&all=1")
        items = response.get("products") if isinstance(response, dict) else response
        if not isinstance(items, list):
            raise RuntimeError("รูปแบบข้อมูลสินค้าจาก ERP ไม่ถูกต้อง")
        seen: list[int] = []
        result = {"upserted": 0, "deactivated": 0}
        with self.db:
            for item in items:
                server_id = item.get("id")
                sku = item.get("sku_code") or item.get("sku")
                if not server_id or not sku:
                    continue
                local_id = self._upsert_product(item, int(server_id), str(sku))
                self._replace_barcodes(local_id, item.get("barcodes") or [])
                self._record_price(local_id, item, branch_id)
                seen.append(int(server_id))
                result["upserted"] += 1
            # สินค้าที่ ERP ไม่ส่งมาแล้ว (เลิกขาย) ปิดใช้งาน ไม่ลบ เพราะบิลเก่ายังอ้างอยู่
            if seen:
                placeholders = ",".join("?" for _ in seen)
                cur = self.db.execute(
                    f"UPDATE products SET active = 0, updated_at = ? "
                    f"WHERE server_id IS NOT NULL AND server_id NOT IN ({placeholders}) AND active = 1",
                    [now(), *seen],
                )
                result["deactivated"] = cur.rowcount
        return result

    def _upsert_product(self, item: dict, server_id: int, sku: str) -> int:
        name = str(item.get("name_th") or item.get("name") or sku)
        unit = str(item.get("unit_name") or "หน่วย")
        is_vat = 1 if item.get("is_vat", True) else 0
        category_id = item.get("product_category_id") or item.get("category_id")
        category_name = item.get("category_name")
        price = str(item.get("pos_price") if item.get("pos_price") is not None else item.get("normal_price") or 0)
        row = self.db.execute("SELECT id FROM products WHERE server_id = ?", (server_id,)).fetchone()
        if row:
            self.db.execute(
                """UPDATE products SET sku = ?, name = ?, unit_name = ?, active = 1, is_vat = ?,
                category_id = ?, category_name = ?, price = ?, updated_at = ? WHERE id = ?""",
                (sku, name, unit, is_vat, category_id, category_name, price, now(), row["id"]),
            )
            return int(row["id"])
        # กัน sku ชนกับแถวที่เคย seed ไว้ก่อนมี server_id: ผูก server_id เข้าแถวเดิมแทนสร้างซ้ำ
        existing = self.db.execute("SELECT id FROM products WHERE sku = ?", (sku,)).fetchone()
        if existing:
            self.db.execute(
                """UPDATE products SET server_id = ?, name = ?, unit_name = ?, active = 1, is_vat = ?,
                category_id = ?, category_name = ?, price = ?, updated_at = ? WHERE id = ?""",
                (server_id, name, unit, is_vat, category_id, category_name, price, now(), existing["id"]),
            )
            return int(existing["id"])
        cur = self.db.execute(
            """INSERT INTO products (server_id, sku, name, unit_name, active, is_vat, category_id, category_name, price, updated_at)
            VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)""",
            (server_id, sku, name, unit, is_vat, category_id, category_name, price, now()),
        )
        return int(cur.lastrowid)

    def _replace_barcodes(self, local_product_id: int, barcodes: list[dict]) -> None:
        self.db.execute("DELETE FROM product_barcodes WHERE product_id = ?", (local_product_id,))
        for code in barcodes:
            value = code.get("barcode") if isinstance(code, dict) else code
            if not value:
                continue
            self.db.execute(
                """INSERT OR REPLACE INTO product_barcodes (barcode, product_id, barcode_type, unit_factor, price, synced_at)
                VALUES (?, ?, ?, ?, ?, ?)""",
                (str(value), local_product_id,
                 str(code.get("barcode_type", "CUSTOM")) if isinstance(code, dict) else "CUSTOM",
                 str(code.get("unit_factor", "1")) if isinstance(code, dict) else "1",
                 str(code.get("price")) if isinstance(code, dict) and code.get("price") is not None else None,
                 now()),
            )

    def _record_price(self, local_product_id: int, item: dict, branch_id: int) -> None:
        price = item.get("pos_price") if item.get("pos_price") is not None else item.get("normal_price")
        if price is None:
            return
        version = str(item.get("price_source") or "catalog")
        # เก็บเป็น version ล่าสุดหนึ่งแถวต่อสินค้า ไม่สะสมประวัติราคาทุกครั้งที่ sync
        self.db.execute(
            "DELETE FROM price_versions WHERE product_id = ? AND version = ?", (local_product_id, "catalog-current")
        )
        self.db.execute(
            """INSERT INTO price_versions (product_id, price, starts_at, ends_at, version, branch_id, synced_at)
            VALUES (?, ?, ?, NULL, 'catalog-current', ?, ?)""",
            (local_product_id, str(money(price)), now(), branch_id, now()),
        )

    # ── cashiers ─────────────────────────────────────────────────
    def pull_cashiers(self, branch_id: int | None = None) -> dict[str, int]:
        """ดึงรายชื่อแคชเชียร์ที่ใช้เครื่องนี้ได้ upsert ตาม server_id

        ไม่เก็บ PIN — พนักงานยืนยันตัวออนไลน์ผ่าน /cashier/login แล้วเก็บ credential
        PBKDF2 ที่ ERP ออกให้ (store_credential) ไว้ใช้ตอนออฟไลน์.  การเห็น
        credential_version ใหม่จาก server ห้ามลบ credential เดิม เพราะเครื่องอาจ
        หลุดเน็ตหลัง reset PIN และยังต้องขายต่อได้จนกว่าจะหมด offline validity.
        """
        response = self.api.get("/api/pos/cashiers")
        rows = response.get("cashiers") if isinstance(response, dict) else response
        if not isinstance(rows, list):
            raise RuntimeError("รูปแบบข้อมูลแคชเชียร์จาก ERP ไม่ถูกต้อง")
        seen: list[int] = []
        upserted = 0
        assigned_user_id = self._get_setting("device_user_id")
        assigned_user_id = int(assigned_user_id) if assigned_user_id is not None else None
        with self.db:
            for row in rows:
                server_id = row.get("id")
                code = row.get("code")
                user_id = row.get("user_id")
                if not server_id or not code or (assigned_user_id is not None and int(user_id or 0) != assigned_user_id):
                    continue
                self._upsert_cashier(
                    int(server_id),
                    str(code),
                    str(row.get("name") or code),
                    int(user_id) if user_id is not None else None,
                    row.get("credential_version"),
                    str(row.get("role") or "cashier"),
                    bool(row.get("must_change_pin") or row.get("force_pin_change")),
                )
                seen.append(int(server_id))
                upserted += 1
            if seen:
                placeholders = ",".join("?" for _ in seen)
                scope = " AND (user_id IS NULL OR user_id != ?)" if assigned_user_id is not None else ""
                parameters = [now(), *seen]
                if assigned_user_id is not None:
                    parameters.append(assigned_user_id)
                self.db.execute(
                    f"UPDATE local_cashiers SET active = 0, revoked_at = COALESCE(revoked_at, ?) "
                    f"WHERE server_id IS NOT NULL AND server_id NOT IN ({placeholders}){scope}",
                    parameters,
                )
        return {"upserted": upserted}

    def _upsert_cashier(self, server_id: int, code: str, name: str, user_id: int | None = None,
                        credential_version: str | None = None,
                        role: str = "cashier", force_pin_change: bool = False) -> int:
        row = self.db.execute("SELECT * FROM local_cashiers WHERE server_id = ?", (server_id,)).fetchone()
        if row:
            self.db.execute(
                """UPDATE local_cashiers SET code = ?, name = ?, active = 1, role = ?,
                user_id = ?, last_synced_at = ?, synced_at = ?, server_credential_version = ?,
                force_pin_change = ?, revoked_at = NULL WHERE id = ?""",
                (code, name, role, user_id, now(), now(), credential_version, int(force_pin_change), row["id"]),
            )
            return int(row["id"])
        existing = self.db.execute("SELECT id FROM local_cashiers WHERE code = ?", (code,)).fetchone()
        if existing:
            self.db.execute(
                """UPDATE local_cashiers SET server_id = ?, user_id = ?, name = ?, active = 1, role = ?,
                last_synced_at = ?, synced_at = ?, server_credential_version = ?, force_pin_change = ?, revoked_at = NULL
                WHERE id = ?""",
                (server_id, user_id, name, role, now(), now(), credential_version, int(force_pin_change), existing["id"]),
            )
            return int(existing["id"])
        cur = self.db.execute(
            """INSERT INTO local_cashiers (server_id, user_id, code, name, pin_hash, active, role, synced_at, last_synced_at,
            server_credential_version, force_pin_change) VALUES (?, ?, ?, ?, '', 1, ?, ?, ?, ?, ?)""",
            (server_id, user_id, code, name, role, now(), now(), credential_version, int(force_pin_change)),
        )
        return int(cur.lastrowid)

    def store_credential(self, server_cashier_id: int, credential: dict) -> None:
        """Store a newly verified credential without invalidating a valid old PIN.

        A remote reset may reach the POS while it is about to lose connectivity.
        Archive the previous verifier before replacing it, retaining it only to
        its original offline expiry.
        """
        if not credential:
            return
        with self.db:
            current = self.db.execute("SELECT * FROM local_cashiers WHERE server_id = ?", (server_cashier_id,)).fetchone()
            if not current:
                raise RuntimeError("ไม่พบแคชเชียร์ local สำหรับเก็บ credential")
            new_version = str(credential.get("credential_version") or "")
            if (
                current["cred_salt"] and current["cred_verifier"] and current["credential_version"]
                and str(current["credential_version"]) != new_version and current["offline_valid_until"]
            ):
                self.db.execute(
                    """INSERT OR IGNORE INTO cashier_credential_history
                    (cashier_id, credential_version, cred_salt, cred_verifier, cred_iterations, offline_valid_until, superseded_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)""",
                    (current["id"], current["credential_version"], current["cred_salt"], current["cred_verifier"],
                     current["cred_iterations"] or 0, current["offline_valid_until"], TimeService(self.db).now_iso()),
                )
            self.db.execute(
                """UPDATE local_cashiers SET cred_salt = ?, cred_verifier = ?, cred_iterations = ?, cred_expires_at = ?,
                offline_valid_until = ?, credential_version = ?, server_credential_version = ?, force_pin_change = 0,
                local_override_pin_hash = NULL, local_override_expires_at = NULL, local_override_set_by = NULL
                WHERE server_id = ?""",
                (credential.get("salt"), credential.get("verifier"), int(credential.get("iterations") or 0),
                 credential.get("expires_at"), credential.get("expires_at"), credential.get("credential_version"),
                 credential.get("credential_version"), server_cashier_id),
            )

    # ── cashier login (ออนไลน์) ──────────────────────────────────
    def online_cashier_login(self, pin: str, cashier_code: str | None = None,
                             cashier_server_id: int | None = None) -> dict:
        """ยืนยัน PIN กับ ERP แล้วเก็บ credential ออฟไลน์ + ผูก local cashier row

        คืน {"selection_required": True, "cashiers": [...]} เมื่อ PIN กลางตรงหลายคน
        (UI ต้องให้เลือกชื่อ) หรือ {"cashier": {...}, "local_cashier_id": n} เมื่อสำเร็จ
        """
        payload: dict = {"pin": pin}
        if cashier_code:
            payload["code"] = cashier_code
        if cashier_server_id is not None:
            payload["cashier_id"] = int(cashier_server_id)
        response = self.api.post("/api/pos/cashier/login", payload)
        if not response.get("success", False):
            raise RuntimeError(response.get("message", "PIN ไม่ถูกต้อง"))
        if response.get("selection_required"):
            return {"selection_required": True, "cashiers": response.get("cashiers", [])}

        cashier = response.get("cashier") or {}
        server_id = cashier.get("id")
        if not server_id:
            raise RuntimeError("ERP ยืนยันแคชเชียร์แล้วแต่ไม่คืนรหัส")
        with self.db:
            local_id = self._upsert_cashier(
                int(server_id),
                str(cashier.get("code") or server_id),
                str(cashier.get("name") or ""),
                cashier.get("credential_version"),
                str(cashier.get("role") or "cashier"),
                bool(cashier.get("must_change_pin")),
            )
        credential = response.get("offline_credential")
        if credential:
            self.store_credential(int(server_id), credential)
        return {
            "selection_required": False,
            "cashier": cashier,
            "local_cashier_id": local_id,
            "must_change_pin": bool(response.get("must_change_pin")),
        }

    def authorize_admin(self, username: str, password: str) -> dict:
        response = self.api.post("/api/pos/admin/authorize", {
            "username": username,
            "password": password,
        })
        if not response.get("success", False):
            raise RuntimeError(response.get("message", "ผู้ดูแลไม่มีสิทธิ์ตั้งค่า POS"))
        return response

    def change_cashier_pin(self, code: str, current_pin: str, new_pin: str) -> dict:
        """เปลี่ยน PIN กับ ERP แล้วเก็บ offline credential รุ่นใหม่ที่ server ออกให้"""
        response = self.api.post("/api/pos/cashier/pin", {
            "code": code,
            "current_pin": current_pin,
            "new_pin": new_pin,
        })
        if not response.get("success", False):
            raise RuntimeError(response.get("message", "เปลี่ยน PIN ไม่สำเร็จ"))

        cashier = response.get("cashier") or {}
        server_id = cashier.get("id")
        if not server_id:
            raise RuntimeError("ERP เปลี่ยน PIN แล้วแต่ไม่คืนรหัสแคชเชียร์")
        with self.db:
            local_id = self._upsert_cashier(
                int(server_id),
                str(cashier.get("code") or server_id),
                str(cashier.get("name") or ""),
                cashier.get("credential_version"),
                str(cashier.get("role") or "cashier"),
                bool(cashier.get("must_change_pin")),
            )
        credential = response.get("offline_credential")
        if credential:
            self.store_credential(int(server_id), credential)

        return {"cashier": cashier, "local_cashier_id": local_id}

    # ── shift ────────────────────────────────────────────────────
    def open_server_shift(self, *, branch_id: int, cashier_server_id: int, opening_cash, local_shift_id: int) -> int:
        """เปิดกะฝั่ง ERP แล้วผูก server_id กลับเข้ากะ local — ต้องทำก่อนขายถึงจะ sync บิลได้"""
        response = self.api.post("/api/pos/shift/open", {
            "branch_id": int(branch_id),
            "cashier_id": int(cashier_server_id),
            "opening_cash": str(money(opening_cash)),
        })
        if not response.get("success", False):
            raise RuntimeError(response.get("message", "เปิดกะที่ ERP ไม่สำเร็จ"))
        shift = response.get("shift") or {}
        server_shift_id = shift.get("id")
        if not server_shift_id:
            raise RuntimeError("ERP เปิดกะแล้วแต่ไม่คืนเลขกะ")
        self.db.execute("UPDATE shifts SET server_id = ? WHERE id = ?", (int(server_shift_id), local_shift_id))
        self.db.commit()
        return int(server_shift_id)

    # ── orchestrator ─────────────────────────────────────────────
    def sync_down(self, branch_id: int) -> dict[str, Any]:
        """ดึงข้อมูลลงเครื่องทั้งชุด — mirror ของ syncAll ขั้นที่ 2 ฝั่ง Tauri"""
        return {
            "catalog": self.pull_catalog(branch_id),
            "cashiers": self.pull_cashiers(branch_id),
        }

    # ── helpers ──────────────────────────────────────────────────
    def _put_setting(self, key: str, value: Any) -> None:
        self.db.execute("DELETE FROM device_settings WHERE key = ?", (key,))
        self.db.execute(
            "INSERT INTO device_settings (key, value, updated_at) VALUES (?, ?, ?)",
            (key, json.dumps(value, ensure_ascii=False), now()),
        )

    def _get_setting(self, key: str) -> Any:
        row = self.db.execute("SELECT value FROM device_settings WHERE key = ?", (key,)).fetchone()
        if not row:
            return None
        try:
            return json.loads(row["value"])
        except (ValueError, TypeError):
            return row["value"]
