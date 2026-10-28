"""หน้า สถานะเครื่อง ต้องบอกได้ทันทีว่าเครื่องเป็นของใคร เชื่อม ERP ไหม และมีอะไรค้าง"""
from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from pos_python import device_status
from pos_python.database import connect


class DeviceStatusTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def _set(self, key: str, value) -> None:
        self.db.execute("INSERT OR REPLACE INTO device_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))",
                        (key, json.dumps(value)))

    def _outbox(self, uuid: str, status: str) -> None:
        self.db.execute(
            "INSERT INTO sync_outbox (aggregate_type, aggregate_uuid, payload, status, created_at)"
            " VALUES ('sale', ?, '{}', ?, datetime('now'))", (uuid, status))

    def test_an_unpaired_till_says_to_paste_the_token(self) -> None:
        status = device_status.collect(self.db)

        self.assertFalse(status.paired)
        self.assertEqual("bad", status.level)
        self.assertIn("วาง Token", status.headline)

    def test_a_paired_online_till_reports_identity_and_pending_bills(self) -> None:
        self._set("branch_name", "สาขาอำนาจเจริญ")
        self._set("terminal_code", "POS-B001-01")
        self._outbox("a", "pending")
        self.db.execute(
            "INSERT INTO sync_state (entity, status, last_success_at, item_count, updated_at)"
            " VALUES ('catalog', 'synced', '2026-11-05 08:00', 1622, datetime('now'))")

        status = device_status.collect(self.db, server_url="https://popstarcenter.com", online=True)

        self.assertEqual("สาขาอำนาจเจริญ", status.branch)
        self.assertEqual("POS-B001-01", status.terminal)
        self.assertEqual(1, status.pending_sales)
        self.assertEqual("ok", status.level)
        catalog = next(d for d in status.datasets if d.key == "catalog")
        self.assertEqual("ok", catalog.state)
        self.assertIn("1622", catalog.detail)

    def test_bills_the_erp_refused_turn_the_status_red(self) -> None:
        self._outbox("b", "needs_review")

        status = device_status.collect(self.db, server_url="https://popstarcenter.com", online=True)

        self.assertEqual(1, status.review_sales)
        self.assertEqual("bad", status.level)
        self.assertIn("ไม่รับบิล 1 ใบ", status.headline)

    def test_offline_is_a_warning_not_a_stop(self) -> None:
        status = device_status.collect(self.db, server_url="https://popstarcenter.com", online=False, error="timeout")

        self.assertEqual("warn", status.level)
        self.assertIn("ขายต่อได้", status.headline)


if __name__ == "__main__":
    unittest.main()
