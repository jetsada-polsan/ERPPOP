"""ดึงข้อมูลลงเครื่องต้องผูก server_id ให้ถูก และ upsert ไม่ทำลายของเดิม"""
from __future__ import annotations

import base64
import hashlib
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from pos_python.database import connect
from pos_python.provisioning import ProvisioningService
from pos_python.services import PosService
from pos_python.time_service import TimeService


class FakeApi:
    """จำลอง Laravel API — ตอบตาม path ที่เรียก บันทึก payload ที่ POST เข้ามา"""
    def __init__(self, responses: dict):
        self.responses = responses
        self.posted: list[tuple[str, dict]] = []

    def get(self, path: str) -> dict:
        for key, value in self.responses.items():
            if path.startswith(key):
                return value
        raise AssertionError(f"ไม่ได้เตรียมคำตอบให้ {path}")

    def post(self, path: str, payload: dict, *, idempotency_key=None) -> dict:
        self.posted.append((path, payload))
        return self.responses[path]


PING = {
    "success": True,
    "branch_id": 3,
    "branch_name": "สาขาวาริน",
    "device": {"terminal_code": "POS-0003-01"},
    "vat_rate": 7.0,
    "cashier_login_mode": "pin",
    "company": {"name": "ป๊อบสตาร์"},
    "scale_profiles": [
        {"code": "S800", "prefix": "800", "plu_length": 6, "value_length": 6,
         "value_type": "price", "check_digit": "ean13", "total_length": 13},
    ],
}
PRODUCTS = {"success": True, "products": [
    {"id": 101, "sku_code": "A001", "name_th": "หมูสามชั้น", "unit_name": "กก.", "is_vat": True,
     "product_category_id": 5, "pos_price": 189.0, "normal_price": 189.0,
     "barcodes": [{"barcode": "8850001", "barcode_type": "EAN13", "unit_factor": "1", "price": None}]},
    {"id": 102, "sku_code": "A002", "name_th": "น้ำจิ้ม", "unit_name": "ขวด", "is_vat": False,
     "pos_price": 69.0, "barcodes": []},
]}
CASHIERS = {"success": True, "cashiers": [
    {"id": 42, "code": "C001", "name": "สมชาย", "credential_version": "2026-09-01T00:00:00Z"},
    {"id": 43, "code": "C002", "name": "สมหญิง", "credential_version": "2026-09-01T00:00:00Z"},
]}


class ProvisioningTest(unittest.TestCase):
    def setUp(self) -> None:
        self.db = connect(Path(tempfile.mkdtemp()) / "prov.sqlite")
        self.api = FakeApi({
            "/api/pos/ping": PING,
            "/api/pos/products": PRODUCTS,
            "/api/pos/cashiers": CASHIERS,
            "/api/pos/shift/open": {"success": True, "shift": {"id": 9001}},
        })
        self.svc = ProvisioningService(self.db, self.api)

    def test_ping_caches_the_device_profile_for_offline_use(self) -> None:
        self.svc.ping()
        settings = {r["key"]: r["value"] for r in self.db.execute("SELECT key, value FROM device_settings")}
        self.assertIn("branch_id", settings)
        self.assertEqual(settings["branch_id"], "3")
        self.assertIn("terminal_code", settings)
        # กฎเครื่องชั่งถูกเก็บจาก ping
        self.assertEqual(self.db.execute("SELECT count(*) FROM scale_profiles").fetchone()[0], 1)

    def test_ping_calibrates_the_local_clock_from_erp_time(self) -> None:
        self.api.responses["/api/pos/ping"] = {
            **PING,
            "server_time": (datetime.now(timezone.utc) + timedelta(seconds=90)).isoformat(),
        }

        self.svc.ping()

        self.assertGreaterEqual(TimeService(self.db).offset_seconds(), 88)

    def test_catalog_upserts_products_with_server_id_and_barcodes(self) -> None:
        self.svc.pull_catalog(3)
        rows = {r["server_id"]: r for r in self.db.execute("SELECT * FROM products")}
        self.assertEqual(set(rows), {101, 102})
        self.assertEqual(rows[101]["sku"], "A001")
        self.assertEqual(rows[101]["is_vat"], 1)
        self.assertEqual(rows[102]["is_vat"], 0)
        # บาร์โค้ดผูกกับ local id ของสินค้าตัวเดียวกัน
        bc = self.db.execute("SELECT product_id FROM product_barcodes WHERE barcode = ?", ("8850001",)).fetchone()
        self.assertEqual(bc["product_id"], rows[101]["id"])

    def test_catalog_binds_server_id_onto_a_seeded_row_instead_of_duplicating(self) -> None:
        # เครื่องที่ seed สินค้า sku A001 ไว้ก่อน (ยังไม่มี server_id)
        self.db.execute(
            "INSERT INTO products (sku, name, unit_name, active, updated_at) VALUES ('A001','เก่า','กก.',1,'t')")
        self.db.commit()
        self.svc.pull_catalog(3)
        same = self.db.execute("SELECT count(*) FROM products WHERE sku = 'A001'").fetchone()[0]
        self.assertEqual(same, 1, "ต้องผูก server_id เข้าแถวเดิม ไม่สร้างซ้ำ")
        self.assertEqual(self.db.execute("SELECT server_id FROM products WHERE sku='A001'").fetchone()[0], 101)

    def test_products_no_longer_sent_are_deactivated_not_deleted(self) -> None:
        self.svc.pull_catalog(3)
        self.api.responses["/api/pos/products"] = {"success": True, "products": [PRODUCTS["products"][0]]}
        result = self.svc.pull_catalog(3)
        self.assertEqual(result["deactivated"], 1)
        gone = self.db.execute("SELECT active FROM products WHERE server_id = 102").fetchone()
        self.assertEqual(gone["active"], 0)
        self.assertIsNotNone(gone, "ต้องยังอยู่ (ปิดใช้งาน) ไม่ลบทิ้ง")

    def test_cashiers_upsert_with_server_id(self) -> None:
        self.svc.pull_cashiers(3)
        rows = {r["server_id"]: r["code"] for r in self.db.execute("SELECT server_id, code FROM local_cashiers WHERE server_id IS NOT NULL")}
        self.assertEqual(rows, {42: "C001", 43: "C002"})

    def test_store_credential_writes_pbkdf2_fields(self) -> None:
        self.svc.pull_cashiers(3)
        self.svc.store_credential(42, {"salt": "c2FsdA==", "verifier": "dmVy", "iterations": 120000,
                                       "expires_at": "2026-09-01T00:00:00Z",
                                       "credential_version": "2026-09-01T00:00:00Z"})
        row = self.db.execute("SELECT cred_salt, cred_iterations, credential_version FROM local_cashiers WHERE server_id = 42").fetchone()
        self.assertEqual(row["cred_salt"], "c2FsdA==")
        self.assertEqual(row["cred_iterations"], 120000)
        self.assertEqual(row["credential_version"], "2026-09-01T00:00:00Z")

    def test_replacing_credential_archives_the_previous_valid_verifier(self) -> None:
        self.svc.pull_cashiers(3)
        expires = (datetime.now(timezone.utc) + timedelta(days=1)).isoformat()
        old_salt = b"old-salt"
        new_salt = b"new-salt"
        old = {
            "salt": base64.b64encode(old_salt).decode(),
            "verifier": base64.b64encode(hashlib.pbkdf2_hmac("sha256", b"1111", old_salt, 120000, dklen=32)).decode(),
            "iterations": 120000, "expires_at": expires, "credential_version": "v1",
        }
        new = {
            "salt": base64.b64encode(new_salt).decode(),
            "verifier": base64.b64encode(hashlib.pbkdf2_hmac("sha256", b"2222", new_salt, 120000, dklen=32)).decode(),
            "iterations": 120000, "expires_at": expires, "credential_version": "v2",
        }
        self.svc.store_credential(42, old)
        self.svc.store_credential(42, new)

        self.assertEqual(self.db.execute("SELECT count(*) FROM cashier_credential_history").fetchone()[0], 1)
        auth = PosService(self.db)
        self.assertTrue(auth.login_offline("C001", "1111").success)
        self.assertTrue(auth.login_offline("C001", "2222").success)

    def test_cashier_sync_keeps_valid_offline_credential_when_server_version_changes(self) -> None:
        self.svc.pull_cashiers(3)
        self.svc.store_credential(42, {"salt": "c2FsdA==", "verifier": "dmVy", "iterations": 120000,
                                       "expires_at": "2026-09-01T00:00:00Z",
                                       "credential_version": "2026-09-01T00:00:00Z"})
        self.api.responses["/api/pos/cashiers"] = {"success": True, "cashiers": [
            {"id": 42, "code": "C001", "name": "สมชาย", "credential_version": "2026-09-02T00:00:00Z"},
        ]}

        self.svc.pull_cashiers(3)

        row = self.db.execute("SELECT cred_salt, cred_verifier, credential_version, server_credential_version FROM local_cashiers WHERE server_id = 42").fetchone()
        self.assertEqual(row["cred_salt"], "c2FsdA==")
        self.assertEqual(row["cred_verifier"], "dmVy")
        # credential_version tells which verifier is cached; server_credential_version
        # announces a newer PIN without breaking a disconnected terminal.
        self.assertEqual(row["credential_version"], "2026-09-01T00:00:00Z")
        self.assertEqual(row["server_credential_version"], "2026-09-02T00:00:00Z")

    def test_open_server_shift_binds_the_server_id_back(self) -> None:
        self.svc.pull_cashiers(3)  # ต้องมีแคชเชียร์ให้ shift.cashier_id อ้าง (FK)
        local_cashier = self.db.execute("SELECT id FROM local_cashiers LIMIT 1").fetchone()["id"]
        # กะ local ที่เปิดไว้แล้ว (ยังไม่มี server_id)
        self.db.execute(
            "INSERT INTO shifts (uuid, branch_id, terminal_id, cashier_id, opened_at, opening_cash, status) "
            "VALUES ('u1', 3, 'POS-0003-01', ?, 't', '1000', 'open')", (local_cashier,))
        self.db.commit()
        local_id = self.db.execute("SELECT id FROM shifts WHERE uuid = 'u1'").fetchone()["id"]
        server_id = self.svc.open_server_shift(branch_id=3, cashier_server_id=42, opening_cash=1000, local_shift_id=local_id)
        self.assertEqual(server_id, 9001)
        self.assertEqual(self.db.execute("SELECT server_id FROM shifts WHERE id = ?", (local_id,)).fetchone()[0], 9001)
        # payload ที่ส่งไปต้องใช้ cashier_id ฝั่ง server
        self.assertEqual(self.api.posted[-1][1]["cashier_id"], 42)

    def test_sync_down_pulls_both_catalog_and_cashiers(self) -> None:
        out = self.svc.sync_down(3)
        self.assertEqual(out["catalog"]["upserted"], 2)
        self.assertEqual(out["cashiers"]["upserted"], 2)

    def test_ping_caches_published_pos_layout(self) -> None:
        self.api.responses["/api/pos/ping"]["pos_layout"] = {
            "schema": "popcentral-pos-layout", "version": 3,
            "components": [{"id": "cart", "type": "cart", "x": 1, "y": 1, "w": 6, "h": 4}],
        }
        self.svc.ping()
        row = self.db.execute("SELECT value FROM device_settings WHERE key = 'pos_layout'").fetchone()
        self.assertIsNotNone(row)
        self.assertIn('"version": 3', row[0])


if __name__ == "__main__":
    unittest.main()
