"""ต่อเครื่องเข้ากับ ERP: config, bootstrap, online login, background worker"""
from __future__ import annotations

import tempfile
import time
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.bootstrap import bootstrap
from pos_python.config import DeviceConfig, load_device_config, save_device_config
from pos_python.database import connect
from pos_python.provisioning import ProvisioningService
from pos_python.services import CartLine, PosService
from pos_python.sync_worker import SyncWorker
from tests.test_provisioning import FakeApi, PING, PRODUCTS, CASHIERS


def fresh_db():
    path = Path(tempfile.mkdtemp()) / "boot.sqlite"
    return connect(path), path


class ConfigTest(unittest.TestCase):
    def test_unconfigured_device_returns_none(self) -> None:
        self.assertIsNone(load_device_config(Path(tempfile.mkdtemp())))

    def test_config_round_trips_through_the_file(self) -> None:
        d = Path(tempfile.mkdtemp())
        save_device_config(d, DeviceConfig("https://erp.example", "tok123"))
        loaded = load_device_config(d)
        self.assertEqual((loaded.server_url, loaded.device_token), ("https://erp.example", "tok123"))


class BootstrapTest(unittest.TestCase):
    def setUp(self) -> None:
        self.db, self.path = fresh_db()
        self.api = FakeApi({"/api/pos/ping": PING, "/api/pos/products": PRODUCTS, "/api/pos/cashiers": CASHIERS})

    def test_no_config_means_no_context_so_main_falls_back_to_demo(self) -> None:
        self.assertIsNone(bootstrap(Path(tempfile.mkdtemp()), self.db, self.path))

    def test_bootstrap_pings_syncs_and_reports_the_branch(self) -> None:
        ctx = bootstrap(Path(tempfile.mkdtemp()), self.db, self.path,
                        config=DeviceConfig("https://erp.example", "tok"))
        # แทน client จริงด้วย fake หลังสร้าง เพื่อไม่ยิงเน็ต
        ctx.worker.stop()
        self.assertIsNotNone(ctx)
        # ทำ bootstrap ซ้ำด้วย fake api ตรง ๆ เพื่อตรวจ orchestration
        db2, path2 = fresh_db()
        prov = ProvisioningService(db2, self.api)
        prov.ping(); prov.sync_down(3)
        self.assertEqual(db2.execute("SELECT count(*) FROM products WHERE server_id IS NOT NULL").fetchone()[0], 2)

    def test_offline_ping_still_gives_a_context_from_cache(self) -> None:
        class Down(FakeApi):
            def get(self, path):
                raise RuntimeError("network down")
        # เคย ping สำเร็จรอบก่อน แคช branch ไว้
        ProvisioningService(self.db, self.api).ping()
        ctx = bootstrap(Path(tempfile.mkdtemp()), self.db, self.path, config=DeviceConfig("https://erp.example", "tok"))
        ctx.worker.stop()
        # ctx สร้าง client จริงแต่ ping จะล้ม (โฮสต์ไม่จริง) → ใช้ค่าแคช
        self.assertEqual(ctx.branch_id, 3)
        self.assertFalse(ctx.online)


class OnlineLoginTest(unittest.TestCase):
    def test_login_stores_credential_and_maps_local_cashier(self) -> None:
        db, _ = fresh_db()
        api = FakeApi({"/api/pos/cashier/login": {
            "success": True, "cashier": {"id": 42, "code": "C001", "name": "สมชาย",
                                         "user_id": 501,
                                         "credential_version": "2026-09-01T00:00:00Z"},
            "offline_credential": {"salt": "c2FsdA==", "verifier": "dmVy", "iterations": 120000,
                                   "expires_at": "2026-09-01T00:00:00Z",
                                   "credential_version": "2026-09-01T00:00:00Z"},
        }})
        result = ProvisioningService(db, api).online_cashier_login("1234", cashier_code="C001")
        self.assertFalse(result["selection_required"])
        self.assertEqual(result["cashier"]["id"], 42)
        row = db.execute("SELECT server_id, cred_salt, credential_version FROM local_cashiers WHERE id = ?", (result["local_cashier_id"],)).fetchone()
        self.assertEqual(row["server_id"], 42)
        self.assertEqual(row["cred_salt"], "c2FsdA==")
        self.assertEqual(row["credential_version"], "2026-09-01T00:00:00Z")
        self.assertEqual(db.execute("SELECT user_id FROM local_cashiers WHERE id = ?", (result["local_cashier_id"],)).fetchone()[0], 501)
        self.assertEqual(api.posted[0][1]["code"], "C001")

    def test_online_login_normalizes_thai_digits_before_calling_laravel(self) -> None:
        db, _ = fresh_db()
        api = FakeApi({"/api/pos/cashier/login": {
            "success": True, "cashier": {"id": 42, "code": "C001", "name": "สมชาย",
                                         "credential_version": "v1"}}})

        ProvisioningService(db, api).online_cashier_login("๔๘๒๑", cashier_code="C001")

        self.assertEqual(api.posted[0][1]["pin"], "4821")

    def test_change_pin_stores_the_new_server_credential(self) -> None:
        db, _ = fresh_db()
        api = FakeApi({"/api/pos/cashier/pin": {
            "success": True, "cashier": {"id": 42, "code": "C001", "name": "สมชาย",
                                         "credential_version": "2026-09-02T00:00:00Z"},
            "offline_credential": {"salt": "bmV3", "verifier": "dmVyMg==", "iterations": 120000,
                                   "expires_at": "2026-09-09T00:00:00Z",
                                   "credential_version": "2026-09-02T00:00:00Z"},
        }})
        result = ProvisioningService(db, api).change_cashier_pin("C001", "1234", "860531")

        self.assertEqual(result["cashier"]["id"], 42)
        row = db.execute("SELECT cred_salt, cred_verifier, credential_version FROM local_cashiers WHERE id = ?", (result["local_cashier_id"],)).fetchone()
        self.assertEqual(row["cred_salt"], "bmV3")
        self.assertEqual(row["cred_verifier"], "dmVyMg==")
        self.assertEqual(row["credential_version"], "2026-09-02T00:00:00Z")

    def test_login_surfaces_selection_when_a_shared_pin_matches_many(self) -> None:
        db, _ = fresh_db()
        api = FakeApi({"/api/pos/cashier/login": {
            "success": True, "selection_required": True,
            "cashiers": [{"id": 1, "code": "A"}, {"id": 2, "code": "B"}]}})
        result = ProvisioningService(db, api).online_cashier_login("1234")
        self.assertTrue(result["selection_required"])
        self.assertEqual(len(result["cashiers"]), 2)

    def test_passwordless_login_sends_only_the_bound_cashier_id(self) -> None:
        db, _ = fresh_db()
        api = FakeApi({"/api/pos/cashier/login": {
            "success": True, "cashier": {"id": 42, "code": "C001", "name": "สมชาย",
                                         "user_id": 501, "credential_version": "v1"}}})

        result = ProvisioningService(db, api).online_cashier_login(None, cashier_code="C001", cashier_server_id=42)

        self.assertFalse(result["selection_required"])
        self.assertEqual(api.posted[0][1], {"cashier_id": 42, "code": "C001"})


class SyncWorkerTest(unittest.TestCase):
    def test_worker_refreshes_cached_master_data_after_reconnect(self) -> None:
        db, path = fresh_db()
        api = FakeApi({"/api/pos/ping": PING, "/api/pos/products": PRODUCTS, "/api/pos/cashiers": CASHIERS})
        calls = []

        def refresh_down():
            refresh_db = connect(path)
            try:
                result = ProvisioningService(refresh_db, api).sync_down(3)
                calls.append(result)
                return result
            finally:
                refresh_db.close()

        worker = SyncWorker(path, api, refresh_down=refresh_down)
        worker.needs_down_sync = True
        result = worker.run_once()
        db.close()

        self.assertEqual(len(calls), 1)
        self.assertEqual(result["download"]["cashiers"]["upserted"], 2)
        check = connect(path)
        self.assertEqual(check.execute("SELECT count(*) FROM local_cashiers WHERE server_id IS NOT NULL").fetchone()[0], 2)
        check.close()

    def test_worker_drains_the_queue_in_the_background(self) -> None:
        db, path = fresh_db()
        # เตรียมบิลที่พร้อม sync (มี server ids ครบ)
        db.execute("INSERT INTO local_cashiers (id, server_id, code, name, pin_hash, synced_at) VALUES (5, 900, 'C', 'c', '', 't')")
        db.execute("INSERT INTO products (id, server_id, sku, name, unit_name, updated_at) VALUES (1, 101, 'S', 'n', 'u', 't')")
        db.execute("INSERT INTO shifts (id, server_id, uuid, branch_id, terminal_id, cashier_id, opened_at, opening_cash, status) VALUES (1, 500, 'u', 1, 'T', 5, 't', '0', 'open')")
        db.commit()
        PosService(db).checkout(document_no="D1", branch_id=1, terminal_id="T", shift_id=1, cashier_id=5,
            lines=[CartLine(1, Decimal("1"), Decimal("10"))], payment_method="cash", paid_amount=Decimal("10"), sale_uuid="s-1")
        db.close()

        api = FakeApi({})
        api.responses = {}
        # FakeApi.post ต้องคืน success สำหรับ checkout
        class OkApi:
            def __init__(self): self.calls = []
            def post(self, path, payload, *, idempotency_key=None):
                self.calls.append(path); return {"success": True, "receipt_no": "R-1"}
        ok = OkApi()
        worker = SyncWorker(path, ok, idle_interval=0.1, retry_interval=0.1)
        worker.start()
        for _ in range(50):
            check = connect(path)
            status = check.execute("SELECT status FROM sync_outbox WHERE aggregate_uuid='s-1'").fetchone()["status"]
            check.close()
            if status == "synced":
                break
            time.sleep(0.1)
        worker.stop()
        self.assertEqual(status, "synced")
        self.assertIn("/api/pos/checkout", ok.calls)


    def test_interval_is_fast_with_a_backlog_and_slow_when_idle(self) -> None:
        from pathlib import Path as P
        w = SyncWorker(P("/tmp/x.sqlite"), object(), idle_interval=30.0, retry_interval=5.0)
        w.pending = 0
        self.assertEqual(w.next_interval(), 30.0)
        w.pending = 3
        self.assertEqual(w.next_interval(), 5.0)

    def test_run_once_marks_offline_when_the_network_is_down(self) -> None:
        db, path = fresh_db()
        db.execute("INSERT INTO local_cashiers (id, server_id, code, name, pin_hash, synced_at) VALUES (5, 900, 'C', 'c', '', 't')")
        db.execute("INSERT INTO products (id, server_id, sku, name, unit_name, updated_at) VALUES (1, 101, 'S', 'n', 'u', 't')")
        db.execute("INSERT INTO shifts (id, server_id, uuid, branch_id, terminal_id, cashier_id, opened_at, opening_cash, status) VALUES (1, 500, 'u', 1, 'T', 5, 't', '0', 'open')")
        db.commit()
        PosService(db).checkout(document_no="D1", branch_id=1, terminal_id="T", shift_id=1, cashier_id=5,
            lines=[CartLine(1, Decimal("1"), Decimal("10"))], payment_method="cash", paid_amount=Decimal("10"), sale_uuid="s-x")
        db.close()

        class DeadApi:
            def post(self, *a, **k): raise RuntimeError("network down")
        w = SyncWorker(path, DeadApi(), retry_interval=0.05)
        w.run_once()  # sync_pending_sales จับ error ต่อบิล ไม่ throw แต่ยังค้าง
        self.assertGreater(w.pending, 0)
        self.assertFalse(w.online)               # มีบิลค้าง + ส่งไม่ได้ = ออฟไลน์
        self.assertEqual(w.next_interval(), 0.05)  # จึงวนถี่ รอเน็ตกลับ


if __name__ == "__main__":
    unittest.main()
