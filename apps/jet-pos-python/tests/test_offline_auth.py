import base64
import hashlib
from datetime import datetime, timedelta, timezone
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from jet_pos.local_store import LocalStore
from jet_pos.offline_auth import verify_offline_pin


def credential(pin: str, expires_at: datetime) -> dict[str, object]:
    salt = b"jet-pos-test-salt"
    verifier = hashlib.pbkdf2_hmac("sha256", pin.encode(), salt, 1_000, dklen=32)
    return {"salt": base64.b64encode(salt).decode(), "verifier": base64.b64encode(verifier).decode(), "iterations": 1_000, "expires_at": expires_at.isoformat()}


class OfflinePinTest(unittest.TestCase):
    def test_verifies_current_credential_without_storing_pin(self) -> None:
        now = datetime.now(timezone.utc)
        item = credential("1234", now + timedelta(hours=1))
        self.assertTrue(verify_offline_pin("1234", item, now))
        self.assertFalse(verify_offline_pin("9999", item, now))

    def test_rejects_expired_credential(self) -> None:
        now = datetime.now(timezone.utc)
        self.assertFalse(verify_offline_pin("1234", credential("1234", now - timedelta(seconds=1)), now))

    def test_store_finds_cached_cashier_by_verified_pin(self) -> None:
        with TemporaryDirectory() as directory:
            store = LocalStore(Path(directory) / "pos.sqlite")
            try:
                store.cache_offline_cashier({"id": 1, "code": "C001", "name": "Cashier"}, credential("1234", datetime.now(timezone.utc) + timedelta(hours=1)))
                self.assertEqual(store.offline_cashiers_by_pin("1234")[0]["code"], "C001")
                self.assertEqual(store.offline_cashiers_by_pin("9999"), [])
            finally:
                store.close()


if __name__ == "__main__":
    unittest.main()
