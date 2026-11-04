import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from jet_pos.api import PosApiClient
from jet_pos.local_store import LocalStore
from jet_pos.sync import sync_pending_checkouts


class OfflineQueueTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = TemporaryDirectory()
        self.store = LocalStore(Path(self.temporary_directory.name) / "pos.sqlite")

    def tearDown(self) -> None:
        self.store.close()
        self.temporary_directory.cleanup()

    def test_checkout_is_queued_with_a_stable_idempotency_key(self) -> None:
        key = self.store.queue_checkout({"items": [{"product_id": 1}]}, "sale-001")
        self.store.queue_checkout({"items": [{"product_id": 1}]}, "sale-001")
        rows = self.store.pending_checkouts()
        self.assertEqual(key, "sale-001")
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0].payload["idempotency_key"], "sale-001")

    def test_successful_sync_marks_the_local_bill_synced(self) -> None:
        self.store.queue_checkout({"items": []}, "sale-002")

        def transport(method, url, headers, body):
            self.assertEqual(method, "POST")
            self.assertTrue(url.endswith("/api/pos/checkout"))
            self.assertEqual(headers["Idempotency-Key"], "sale-002")
            self.assertEqual(json.loads(body)["idempotency_key"], "sale-002")
            return 201, b'{"receipt_no":"POS-0001"}'

        client = PosApiClient("http://127.0.0.1:8000", "test-token", transport)
        self.assertEqual(sync_pending_checkouts(self.store, client), 1)
        self.assertEqual(self.store.connection.execute("SELECT status, receipt_no FROM checkout_queue").fetchone()["status"], "synced")

    def test_rejected_bill_is_held_for_review_and_is_not_retried(self) -> None:
        self.store.queue_checkout({"items": []}, "sale-003")
        attempts = 0

        def transport(method, url, headers, body):
            nonlocal attempts
            attempts += 1
            return 422, b'{"message":"price differs from ERP"}'

        client = PosApiClient("http://localhost:8000", "test-token", transport)
        self.assertEqual(sync_pending_checkouts(self.store, client), 0)
        self.assertEqual(sync_pending_checkouts(self.store, client), 0)
        self.assertEqual(attempts, 1)
        row = self.store.connection.execute("SELECT status, error FROM checkout_queue").fetchone()
        self.assertEqual(row["status"], "needs_review")
        self.assertIn("price differs", row["error"])


if __name__ == "__main__":
    unittest.main()

