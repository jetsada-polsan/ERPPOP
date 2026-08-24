from __future__ import annotations

import argparse
import os
import traceback
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path

from pos_python.database import connect
from pos_python.mock_printer import print_receipt
from pos_python.services import CartLine, PosService, now, pin_hash
from pos_python.ui import run_ui


ROOT = Path(__file__).parent


def application_data_dir() -> Path:
    """Keep mutable POS data outside the Windows installation directory."""
    if os.name == "nt":
        return Path(os.environ.get("LOCALAPPDATA", Path.home() / "AppData" / "Local")) / "POPSTAR" / "PythonPOS"

    return ROOT / "storage"


DATA_DIR = application_data_dir()
DB_PATH = DATA_DIR / "pos-python-demo.db"


def seed(db) -> None:
    timestamp = now()
    db.execute("INSERT OR IGNORE INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (1, 'POP001', 'แคชเชียร์ทดสอบ', ?, ?)", (pin_hash('1234'), timestamp))
    db.execute("INSERT OR IGNORE INTO products (id, sku, name, unit_name, updated_at) VALUES (1, 'P000001', 'สินค้าทดสอบ POPSTAR', 'ชิ้น', ?)", (timestamp,))
    db.execute("INSERT OR IGNORE INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES ('8850000000003', 1, 'CUSTOM', 25, ?)", (timestamp,))
    db.commit()


def demo() -> None:
    DATA_DIR.mkdir(parents=True, exist_ok=True)
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
    receipt = print_receipt(db, sale_id, DATA_DIR / "receipts")
    print(f"บันทึกบิล {receipt_no}; pending sync={service.pending_sync_count()}; receipt={receipt}")


def launch_ui() -> None:
    """Start the installed application and leave a diagnosable error if startup fails."""
    DATA_DIR.mkdir(parents=True, exist_ok=True)

    try:
        db = connect(DB_PATH)
        seed(db)
        run_ui(PosService(db))
    except Exception as error:
        log_path = DATA_DIR / "startup-error.log"
        log_path.write_text(traceback.format_exc(), encoding="utf-8")

        # A windowed PyInstaller build has no terminal. Surface the exact recovery path to the user.
        try:
            from PySide6.QtWidgets import QApplication, QMessageBox

            created_app = QApplication.instance() is None
            app = QApplication.instance() or QApplication([])
            QMessageBox.critical(
                None,
                "เปิด POPSTAR POS ไม่สำเร็จ",
                f"โปรแกรมเริ่มต้นไม่ได้: {error}\n\n"
                f"กรุณาส่งไฟล์นี้ให้ฝ่าย IT:\n{log_path}",
            )
            if created_app:
                app.quit()
        finally:
            raise SystemExit(1) from error


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--demo", action="store_true")
    parser.add_argument("--ui", action="store_true")
    args = parser.parse_args()
    if args.demo:
        demo()
    elif args.ui:
        launch_ui()
    else:
        launch_ui()


if __name__ == "__main__":
    main()
