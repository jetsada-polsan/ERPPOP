from __future__ import annotations

from .api import PosApiClient, PosApiError
from .local_store import LocalStore


def sync_pending_checkouts(store: LocalStore, client: PosApiClient) -> int:
    synced = 0
    for checkout in store.pending_checkouts():
        store.mark_syncing(checkout.idempotency_key)
        try:
            response = client.checkout(checkout.payload, checkout.idempotency_key)
        except PosApiError as error:
            if error.status_code is not None and 400 <= error.status_code < 500:
                store.mark_problem(checkout.idempotency_key, "needs_review", str(error))
                continue
            store.mark_problem(checkout.idempotency_key, "failed", str(error))
            break
        receipt_no = str(response.get("receipt_no") or response.get("receipt", {}).get("receipt_no") or "")
        if not receipt_no:
            store.mark_problem(checkout.idempotency_key, "needs_review", "ERP accepted checkout without a receipt number")
            continue
        store.mark_synced(checkout.idempotency_key, receipt_no)
        synced += 1
    return synced

