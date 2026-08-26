from __future__ import annotations

import json

import argparse
import os
import traceback
from datetime import datetime, timezone
from decimal import Decimal
from pathlib import Path

from pos_python.bootstrap import bootstrap
from pos_python.database import connect
from pos_python.barcode import replace_scale_profiles
from pos_python.mock_printer import print_receipt
from pos_python.services import CartLine, PosService, now, pin_hash
from pos_python.ui import run_ui


ROOT = Path(__file__).parent


def application_data_dir() -> Path:
    """Keep mutable POS data outside the Windows installation directory."""
    if os.name != "nt":
        return ROOT / "storage"

    local = Path(os.environ.get("LOCALAPPDATA", Path.home() / "AppData" / "Local"))
    current = local / "PopCentral" / "POS"

    # เครื่องที่ติดตั้งไว้ตอนโปรแกรมยังชื่อ POPSTAR Python POS เก็บฐานข้อมูลไว้คนละที่
    # ย้ายให้ครั้งเดียวตอนเปิด ไม่งั้นแคชเชียร์เปิดมาเจอเครื่องเปล่าเหมือนยอดขายหายไปหมด
    legacy = local / "POPSTAR" / "PythonPOS"
    if legacy.is_dir() and not current.exists():
        try:
            current.parent.mkdir(parents=True, exist_ok=True)
            legacy.rename(current)
        except OSError:
            return legacy  # ย้ายไม่ได้ก็ใช้ที่เดิมต่อไป ดีกว่าเปิดโปรแกรมไม่ขึ้น

    return current


DATA_DIR = application_data_dir()
DB_PATH = DATA_DIR / "pos-python-demo.db"


def seed(db) -> None:
    """ข้อมูลตั้งต้นสำหรับเครื่องที่เพิ่งติดตั้ง

    เปิดโปรแกรมมาแล้วต้องมีของให้กดขายได้ทันที ไม่ใช่ตารางว่างเปล่าจนดูเหมือนพัง
    ของจริงจะถูกทับด้วยแคตตาล็อกจาก ERP ตอน sync ครั้งแรก
    """
    timestamp = now()
    db.execute(
        "INSERT OR IGNORE INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (1, 'POP001', 'แคชเชียร์ทดสอบ', ?, ?)",
        (pin_hash("1234"), timestamp),
    )

    # รูปแบบป้ายเครื่องชั่ง — ของจริงมาจาก ERP ตอน sync ค่านี้ให้เครื่องใหม่ยิงป้ายได้เลย
    if not db.execute("SELECT 1 FROM scale_profiles LIMIT 1").fetchone():
        replace_scale_profiles(db, [
            {"code": "POPSTAR-800", "prefix": "800", "plu_length": 6, "value_length": 6,
             "value_type": "price", "check_digit": "ean13", "total_length": 13},
            {"code": "POPSTAR-801", "prefix": "801", "plu_length": 6, "value_length": 6,
             "value_type": "price", "check_digit": "ean13", "total_length": 13},
        ])

    # หัวใบเสร็จ — ของจริงตั้งได้ในหน้าตั้งค่า ค่านี้ให้เครื่องใหม่พิมพ์ออกมาอ่านได้เลย
    for key, value in [
        ("company", {"name": "บริษัท ป๊อบสตาร์ฟู้ดเทรดดิ้ง จำกัด", "branch": "สำนักงานใหญ่",
                     "phone": "045-000-000", "tax_id": ""}),
        ("receipt_footer", "ขอบคุณที่ใช้บริการ"),
    ]:
        db.execute(
            "INSERT OR IGNORE INTO device_settings (key, value, updated_at) VALUES (?, ?, ?)",
            (key, json.dumps(value, ensure_ascii=False), timestamp),
        )

    catalogue = [
        (1, "102201", "หมูสามชั้นสไลซ์", "กก.", "ของสด", "189.00", 1, "801001", "SCALE_PLU"),
        (2, "102202", "เนื้อหมูบด", "กก.", "ของสด", "147.00", 1, "801002", "SCALE_PLU"),
        (3, "P001203", "น้ำจิ้มหมูกระทะ", "ขวด", "เครื่องปรุง", "45.00", 1, "8850000000003", "EAN13_STANDARD"),
        (4, "P000775", "ผักรวมสด", "ถุง", "ของสด", "59.00", 0, "2990000000017", "INTERNAL_13"),
        (5, "P000126", "น้ำแข็งหลอด", "ถุง", "เครื่องดื่ม", "25.00", 1, "ICE-01", "CUSTOM"),
        (6, "P000014", "น้ำดื่มขวดเล็ก", "ขวด", "เครื่องดื่ม", "7.00", 1, "8850000000010", "EAN13_STANDARD"),
        (7, "P000320", "กุ้งขาวแช่แข็ง", "แพ็ค", "แช่แข็ง", "239.00", 1, "2990000000024", "INTERNAL_13"),
        (8, "P000410", "ลูกชิ้นปลาแช่แข็ง", "ถุง", "แช่แข็ง", "89.00", 1, "2990000000031", "INTERNAL_13"),
    ]
    for product_id, sku, name, unit, category, price, is_vat, barcode, barcode_type in catalogue:
        db.execute(
            """INSERT OR IGNORE INTO products (id, sku, name, unit_name, category_name, price, is_vat, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)""",
            (product_id, sku, name, unit, category, price, is_vat, timestamp),
        )
        # PLU 801xxx คือรหัสอ้างอิงในเครื่องชั่ง ส่วนฉลาก EAN-13 จะถูกถอดเป็น
        # SCALE_WEIGHT ตอนสแกน ไม่ใช่ barcode row ของสินค้าโดยตรง.
        db.execute(
            "INSERT OR IGNORE INTO product_barcodes (barcode, product_id, barcode_type, price, synced_at) VALUES (?, ?, ?, ?, ?)",
            (barcode or sku, product_id, barcode_type or "CUSTOM", price, timestamp),
        )
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
        # ผูกเครื่องกับ ERP แล้ว: ping + ดึงข้อมูลจริง + เริ่มส่งบิลค้างเบื้องหลัง
        # ยังไม่ผูก (ไม่มี pos-config.json): seed demo ให้เปิดลองใช้ได้ทันที
        online = bootstrap(DATA_DIR, db, DB_PATH)
        if online is None:
            seed(db)
        run_ui(PosService(db), online=online)
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
                "เปิด PopCentral POS ไม่สำเร็จ",
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
