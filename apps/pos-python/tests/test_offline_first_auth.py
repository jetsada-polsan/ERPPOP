from __future__ import annotations

import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path

from pos_python.database import connect
from pos_python.services import PosService, pin_hash
from pos_python.sync_service import SyncService


class AuthApi:
    def __init__(self):
        self.calls: list[tuple[str, dict, str | None]] = []

    def post(self, path: str, payload: dict, *, idempotency_key: str | None = None) -> dict:
        self.calls.append((path, payload, idempotency_key))
        return {"success": True}


class OfflineFirstAuthTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.sqlite")
        self.service = PosService(self.db)

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def cashier(self, code: str, pin: str, **extra) -> None:
        values = {
            "name": code,
            "active": 1,
            "role": "cashier",
            "offline_valid_until": (datetime.now(timezone.utc) + timedelta(days=1)).isoformat(),
            "credential_version": "local-v1",
            "server_credential_version": "server-v1",
        }
        values.update(extra)
        self.db.execute(
            """INSERT INTO local_cashiers
            (code, name, pin_hash, active, role, credential_version, server_credential_version, offline_valid_until, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
            (code, values["name"], pin_hash(pin), values["active"], values["role"], values["credential_version"],
             values["server_credential_version"], values["offline_valid_until"], datetime.now(timezone.utc).isoformat()),
        )
        self.db.commit()

    def test_offline_login_uses_local_cache_and_writes_audit(self) -> None:
        self.cashier("C001", "4821")

        result = self.service.login_offline("C001", "4821", terminal_code="POS001", branch_code="B001")

        self.assertTrue(result.success)
        event = self.db.execute("SELECT * FROM auth_events_outbox").fetchone()
        self.assertEqual((event["cashier_code"], event["event_type"], event["success"]), ("C001", "offline_login", 1))
        self.assertEqual((event["terminal_code"], event["branch_code"]), ("POS001", "B001"))
        queued = self.db.execute(
            "SELECT aggregate_type, priority FROM sync_outbox WHERE aggregate_uuid = ?", (f"auth:{event['event_uuid']}",)
        ).fetchone()
        self.assertEqual((queued["aggregate_type"], queued["priority"]), ("auth_event", 4))

    def test_remote_pin_reset_metadata_does_not_delete_a_still_valid_local_pin(self) -> None:
        self.cashier("C001", "4821", credential_version="old", server_credential_version="new")

        result = self.service.login_offline("C001", "4821")

        self.assertTrue(result.success, "POS offline must keep the last verified PIN until its offline validity ends")

    def test_expired_offline_credential_is_rejected_and_audited(self) -> None:
        self.cashier("C001", "4821", offline_valid_until=(datetime.now(timezone.utc) - timedelta(minutes=1)).isoformat())

        result = self.service.login_offline("C001", "4821")

        self.assertFalse(result.success)
        self.assertEqual(result.reason, "Offline login expired, please reconnect to server")
        self.assertEqual(self.db.execute("SELECT success FROM auth_events_outbox").fetchone()[0], 0)

    def test_missing_offline_validity_never_becomes_an_unlimited_pin(self) -> None:
        self.cashier("C001", "4821", offline_valid_until=None)

        result = self.service.login_offline("C001", "4821")

        self.assertFalse(result.success)
        self.assertIn("ยังไม่มีสิทธิ์ใช้งานออฟไลน์", result.reason or "")

    def test_manager_can_create_a_short_lived_local_recovery_pin(self) -> None:
        self.cashier("M001", "9999", role="manager")
        self.cashier("C001", "4821")

        issued = self.service.manager_override_reset(
            manager_code="M001", manager_pin="9999", cashier_code="C001", temporary_pin="123456", valid_minutes=30,
        )
        logged_in = self.service.login_offline("C001", "123456")

        self.assertTrue(issued.success)
        self.assertTrue(logged_in.success)
        self.assertTrue(logged_in.used_manager_override)
        events = [row[0] for row in self.db.execute("SELECT event_type FROM auth_events_outbox ORDER BY id")]
        self.assertIn("manager_override_reset", events)

    def test_auth_events_sync_idempotently(self) -> None:
        self.cashier("C001", "4821")
        self.service.login_offline("C001", "4821")
        api = AuthApi()

        result = SyncService(self.db, api).sync_auth_events()

        self.assertEqual(result, {"synced": 1, "failed": 0})
        self.assertEqual(api.calls[0][0], "/api/pos/auth-events")
        self.assertEqual(self.db.execute("SELECT synced FROM auth_events_outbox").fetchone()[0], 1)


if __name__ == "__main__":
    unittest.main()
