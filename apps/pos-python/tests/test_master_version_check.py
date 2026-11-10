"""เครื่อง POS เช็คเลขเวอร์ชันข้อมูลหลังบ้านแล้วดึงเฉพาะชุดที่เปลี่ยน"""
from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from pos_python.database import connect
from pos_python.provisioning import MASTER_VERSIONS_KEY, ProvisioningService
from pos_python.sync_worker import SyncWorker

from tests.test_provisioning import CASHIERS, PRODUCTS


class VersionApi:
    def __init__(self, versions: dict):
        self.versions = dict(versions)
        self.gets: list[str] = []
        self.posts: list[dict] = []
        self.promotions: list = []

    def get(self, path: str) -> dict:
        self.gets.append(path)
        if path.startswith("/api/pos/products"):
            return PRODUCTS
        if path.startswith("/api/pos/cashiers"):
            return CASHIERS
        if path.startswith("/api/pos/promotions"):
            return self.promotions
        raise AssertionError(path)

    def post(self, path: str, payload: dict, *, idempotency_key=None) -> dict:
        assert path == "/api/pos/sync-check", path
        self.posts.append(payload)
        return {"success": True, "versions": dict(self.versions)}


class MasterVersionCheckTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db_path = Path(self.tmp.name) / "pos.sqlite"
        self.db = connect(self.db_path)
        self.api = VersionApi({"catalog": "c1", "cashiers": "u1", "promotions": "p1"})
        self.svc = ProvisioningService(self.db, self.api)

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def pulled(self) -> set[str]:
        return {path.split("?")[0].rsplit("/", 1)[-1] for path in self.api.gets}

    def test_full_sync_remembers_versions_so_next_check_pulls_nothing(self) -> None:
        self.svc.sync_down(3)
        self.assertEqual(self.svc._get_setting(MASTER_VERSIONS_KEY), self.api.versions)
        self.api.gets.clear()

        out = self.svc.check_master_versions(3, pending_bills=2, app_version="0.9.9")

        self.assertEqual(out["stale"], [])
        self.assertEqual(self.api.gets, [])
        self.assertEqual(self.api.posts[-1]["pending_bills"], 2)
        self.assertEqual(self.api.posts[-1]["app_version"], "0.9.9")

    def test_only_the_changed_promotions_are_pulled(self) -> None:
        self.svc.sync_down(3)
        self.api.gets.clear()
        self.api.versions["promotions"] = "p2"

        out = self.svc.check_master_versions(3)

        self.assertEqual(out["stale"], ["promotions"])
        self.assertEqual(self.pulled(), {"promotions"})
        self.assertEqual(self.svc._get_setting(MASTER_VERSIONS_KEY)["promotions"], "p2")
        self.assertTrue(self.api.posts[-1].get("pulled"))

    def test_new_products_also_rebind_promotions(self) -> None:
        self.svc.sync_down(3)
        self.api.gets.clear()
        self.api.versions["catalog"] = "c2"

        self.svc.check_master_versions(3)

        self.assertEqual(self.pulled(), {"products", "promotions"})

    def test_failed_pull_keeps_old_version_so_it_retries(self) -> None:
        self.svc.sync_down(3)
        self.api.versions["promotions"] = "p2"
        self.api.promotions = "broken"
        with self.assertRaises(Exception):
            self.svc.check_master_versions(3)
        self.assertEqual(self.svc._get_setting(MASTER_VERSIONS_KEY)["promotions"], "p1")

    def test_old_erp_without_endpoint_is_ignored(self) -> None:
        class OldErp(VersionApi):
            def post(self, path, payload, *, idempotency_key=None):
                raise RuntimeError("HTTP 404: not found")
        svc = ProvisioningService(self.db, OldErp({}))
        self.assertIsNone(svc.check_master_versions(3))

    def test_worker_checks_at_most_once_per_interval(self) -> None:
        calls: list[int] = []

        class NoSales:
            def post(self, *a, **k):
                raise AssertionError("no sales queued")

        worker = SyncWorker(self.db_path, NoSales(), check_master=lambda pending: calls.append(pending) or {},
                            master_check_interval=300)
        worker.online = True
        worker.run_once()
        worker.run_once()
        self.assertEqual(calls, [0])
        worker._last_master_check -= 301
        worker.run_once()
        self.assertEqual(len(calls), 2)

    def test_worker_does_not_check_while_offline(self) -> None:
        calls: list[int] = []
        worker = SyncWorker(self.db_path, None, check_master=lambda pending: calls.append(pending) or {})
        worker.online = False
        worker.run_once()
        self.assertEqual(calls, [])


if __name__ == "__main__":
    unittest.main()
