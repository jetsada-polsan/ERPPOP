from __future__ import annotations

import argparse
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path

from pos_python.database import connect
from pos_python.mock_printer import print_receipt
from pos_python.services import CartLine, PosService, now, pin_hash
from pos_python.ui import run_ui


ROOT = Path(__file__).parent
DB_PATH = ROOT / "storage" / "pos-python-demo.db"


def seed(db) -> None:
    timestamp = now()
    db.execute("INSERT OR IGNORE INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (1, 'POP001', 'แคชเชียร์ทดสอบ', ?, ?)", (pin_hash('1234'), timestamp))
    db.execute("INSERT OR IGNORE INTO products (id, sku, name, unit_name, updated_at) VALUES (1, 'P000001', 'สินค้าทดสอบ POPSTAR', 'ชิ้น', ?)", (timestamp,))
    db.execute("INSERT OR IGNORE INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES ('8850000000003', 1, 'CUSTOM', 25, ?)", (timestamp,))
    db.commit()


def demo() -> None:
    db = connect(DB_PATH)
    seed(db)
    service = PosService(db)
    cashier = service.login("POP001", "1234")
    assert cashier is not None
    shift_id = service.open_shift(1, "PY-TEST-01", int(cashier["id"]), Decimal("500"))
    product = service.lookup_barcode("8850000000003")
    assert product is not None
    receipt_no = "PYTEST-" + datetime.now(timezone.utc).strftime("%Y%m%d%H%M%S")
    sale_id = service.checkout(
        document_no=receipt_no, branch_id=1, terminal_id="PY-TEST-01", shift_id=shift_id,
        cashier_id=int(cashier["id"]), lines=[CartLine(1, Decimal("2"), Decimal("25"), barcode="8850000000003")],
        payment_method="cash", paid_amount=Decimal("50"),
    )
    receipt = print_receipt(db, sale_id, ROOT / "storage" / "receipts")
    print(f"บันทึกบิล {receipt_no}; pending sync={service.pending_sync_count()}; receipt={receipt}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--demo", action="store_true")
    parser.add_argument("--ui", action="store_true")
    args = parser.parse_args()
    if args.demo:
        demo()
    elif args.ui:
        db = connect(DB_PATH)
        seed(db)
        run_ui(PosService(db))
    else:
        parser.print_help()
