import pytest
from sync_worker import SyncWorker
from database.models import QtyPromotion, init_db

def test_sync_worker_offline_detection(tmp_path):
    db_path = str(tmp_path / "sync_test.db")
    init_db(f"sqlite:///{db_path}")

    worker = SyncWorker(db_path, "http://localhost:8000", "test_token")

    # We want to test that if _check_internet returns False, it raises the exception
    # Instead of running the thread, we can call run() and let it hit the exception
    # Or just test _check_internet directly.
    # To avoid infinite loop in run(), we will mock is_running to turn false after one loop.

    # Let's mock _check_internet to return False
    worker._check_internet = lambda: False

    error_caught = []

    def on_sync_finished(success, msg):
        worker.is_running = False # stop loop
        error_caught.append((success, msg))

    worker.sync_finished.connect(on_sync_finished)

    # Call run directly (synchronously) for testing
    worker.run()

    assert len(error_caught) > 0
    success, msg = error_caught[0]
    assert success is False
    assert "No Internet Connection" in msg


def test_sync_worker_refreshes_and_removes_expired_quantity_promotions(tmp_path, monkeypatch):
    db_path = str(tmp_path / "sync_promotions.db")
    session = init_db(f"sqlite:///{db_path}")
    worker = SyncWorker(db_path, "https://erp.example.test", "test_token")
    worker.config["branch_id"] = 6

    class Response:
        def __init__(self, payload):
            self.payload = payload

        def raise_for_status(self):
            pass

        def json(self):
            return self.payload

    responses = iter([
        Response([{
            "id": 12,
            "name": "ซื้อ 3 ลด 10%",
            "promo_type": "discount",
            "product_id": 101,
            "min_qty": 3,
            "discount_type": "percent",
            "discount_value": "10.00",
            "starts_date": "2026-10-01",
            "ends_date": "2026-10-31",
        }]),
        Response([]),
    ])
    monkeypatch.setattr("sync_worker.requests.get", lambda *args, **kwargs: next(responses))

    try:
        assert worker._sync_promotions(session, {"Accept": "application/json"}) is True
        promotion = session.query(QtyPromotion).one()
        assert promotion.server_id == 12
        assert promotion.min_qty == "3"
        assert promotion.discount_value == "10.00"
        assert worker._sync_promotions(session, {"Accept": "application/json"}) is True
        assert session.query(QtyPromotion).count() == 0
    finally:
        session.close()
