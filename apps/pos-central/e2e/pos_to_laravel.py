"""End-to-end desktop POS sale through the Laravel API on an isolated database.

The workflow creates a fresh SQLite ERP with UAT products and a short-lived
device token. This script uses the POS Central catalog sync, cashier login,
local cart/checkout persistence, and SyncWorker checkout request. It never
connects to the production ERP unless explicitly pointed there by its caller.
"""
from __future__ import annotations

import os
import sys
import tempfile
from pathlib import Path

os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

import requests

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from PySide6.QtCore import Qt
from PySide6.QtWidgets import QApplication, QMessageBox

from database.models import Product, Sale, Outbox, init_db
from sync_worker import SyncWorker
from ui.main_window import MainWindow
from ui import receipt_dialog


class SilentReceiptDialog:
    """Keep the checkout UI path real without opening a modal print dialog."""

    def __init__(self, *args, **kwargs):
        pass

    def exec(self):
        return 0


def need(name: str) -> str:
    value = os.environ.get(name)
    if not value:
        raise RuntimeError(f"ขาด env {name}")
    return value


def api_request(method: str, url: str, token: str, **kwargs):
    response = requests.request(
        method,
        url,
        headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
        timeout=30,
        **kwargs,
    )
    if not response.ok:
        try:
            message = response.json().get("message", "")
        except ValueError:
            message = ""
        raise RuntimeError(f"{method} {url} ได้ HTTP {response.status_code}: {message[:300]}")
    return response.json()


def main() -> int:
    base = need("POS_E2E_URL").rstrip("/")
    token = need("POS_E2E_TOKEN")
    pin = os.environ.get("POS_E2E_PIN", "1234")
    db_path = str(Path(tempfile.mkdtemp(prefix="pos-central-e2e-")) / "pos.sqlite")
    api_url = base

    # The real window constructor normally loads a per-user config. Keep this
    # headless test isolated from any developer's installed POS credentials.
    app = QApplication.instance() or QApplication([])
    app.setQuitOnLastWindowClosed(False)
    original_load_config = MainWindow.load_config
    MainWindow.load_config = lambda self: {}
    original_receipt_dialog = receipt_dialog.ReceiptDialog
    receipt_dialog.ReceiptDialog = SilentReceiptDialog
    original_message_methods = {
        name: getattr(QMessageBox, name)
        for name in ("warning", "information", "critical")
    }
    for name in original_message_methods:
        setattr(QMessageBox, name, lambda *args, **kwargs: QMessageBox.StandardButton.Ok)

    window = None
    try:
        worker = SyncWorker(db_path, api_url, token)
        print("1) POS Central pulls the ERP catalog")
        if worker._sync_products() is not True:
            raise RuntimeError("POS Central โหลดสินค้าไม่สำเร็จ")

        profile = api_request("GET", f"{base}/api/pos/ping", token)
        branch_id = int(profile.get("branch_id") or os.environ.get("POS_E2E_BRANCH") or 0)
        if not branch_id:
            raise RuntimeError("ping ไม่คืน branch_id")

        print("2) POS Central signs in a cashier with the test PIN")
        cashiers_data = api_request("GET", f"{base}/api/pos/cashiers", token)
        cashiers = cashiers_data.get("cashiers", [])
        cashier = next(
            (row for row in cashiers if row.get("code") == "pos_e2e" or row.get("user_name") == "POS E2E"),
            None,
        )
        if not cashier:
            raise RuntimeError("ไม่พบ cashier POS E2E ที่ fixture เตรียมไว้")
        login = api_request(
            "POST",
            f"{base}/api/pos/cashier/login",
            token,
            json={"cashier_id": int(cashier["id"]), "pin": pin},
        )
        cashier = login.get("cashier") or cashier
        cashier_id = int(cashier["id"])
        if login.get("must_change_pin"):
            raise RuntimeError("cashier E2E ถูกบังคับเปลี่ยน PIN; fixture ต้องสร้าง PIN สำหรับทดสอบโดยไม่บังคับเปลี่ยน")

        print("3) POS Central opens or resumes the ERP shift")
        shift_result = api_request(
            "POST",
            f"{base}/api/pos/shift/open",
            token,
            json={
                "branch_id": branch_id,
                "cashier_id": cashier_id,
                "opening_cash": 1000,
                "opening_note": "POS Central isolated E2E",
            },
        )
        shift = shift_result.get("shift") or {}
        shift_id = int(shift.get("id") or 0)
        if not shift_id:
            raise RuntimeError("ERP ไม่คืน shift id")

        session = init_db(f"sqlite:///{db_path}")
        try:
            product = (
                session.query(Product)
                .filter(Product.is_active.is_(True), Product.pos_price > 0, Product.is_scale_item.is_(False))
                .filter((Product.stock_qty.is_(None)) | (Product.stock_qty > 0))
                .order_by(Product.id)
                .first()
            )
            if not product:
                raise RuntimeError("ไม่พบสินค้า regular ที่มีราคาและสต๊อกให้ทดสอบ")
            product_id = product.id
            product_name = product.name_th
            product_sku = product.sku_code
        finally:
            session.close()

        config = {
            "api_url": api_url,
            "pos_token": token,
            "branch_id": branch_id,
            "branch_name": profile.get("branch_name") or "UAT",
            "cashier_id": cashier_id,
            "cashier_name": cashier.get("name") or "POS E2E",
            "cashier_code": cashier.get("code") or "pos_e2e",
            "device_id": (profile.get("device") or {}).get("terminal_code") or "POS-E2E",
        }
        window = MainWindow(db_path=db_path)
        window.config = config
        window.start_sync = lambda: False
        window._open_shift_local(1000, server_id=shift_id, synced=True)

        print("4) POS Central scans an ERP-registered barcode and builds the cart")
        scan = api_request(
            "POST",
            f"{base}/api/pos/scan",
            token,
            json={"branch_id": branch_id, "barcode": "UAT-BC-0001", "source": "manual"},
        )
        scanned_product = scan.get("product") or {}
        if int(scanned_product.get("id") or 0) != product_id:
            raise RuntimeError("ERP scan API returned a product different from the cached product")
        matched = scanned_product.get("matched_barcode") or {}

        session = init_db(f"sqlite:///{db_path}")
        try:
            product = session.query(Product).filter_by(id=product_id).one()
            window.add_to_cart(
                product,
                qty=float(scan.get("qty") or 1),
                is_scale=scan.get("mode") == "scale",
                source_barcode=scan.get("barcode") or "UAT-BC-0001",
                barcode_type=scan.get("barcode_type"),
                unit_factor=float(matched.get("unit_factor") or 1),
                unit_name=matched.get("unit_name"),
                price_override=scanned_product.get("pos_price"),
            )
        finally:
            session.close()
        if window.cart_table.rowCount() != 1:
            raise RuntimeError("สินค้าไม่เข้าตะกร้า POS Central")
        cart_product = window.cart_table.item(0, 0)
        if cart_product.data(Qt.UserRole) != product_id:
            raise RuntimeError("รหัสสินค้าในตะกร้าไม่ตรงกับ catalog ของ ERP")

        print("5) POS Central saves checkout locally, then its SyncWorker sends it")
        total = float(window.cart_table.item(0, 3).text().replace(",", ""))
        window.process_payment("เงินสด", total, 0, cash_amount=total)

        session = init_db(f"sqlite:///{db_path}")
        try:
            sale = session.query(Sale).one()
            outbox = session.query(Outbox).one()
            if sale.status != "pending" or outbox.status != "pending":
                raise RuntimeError("checkout ไม่ได้ถูกบันทึกใน local outbox ก่อนส่ง")
            local_sale_id = sale.local_sale_id
        finally:
            session.close()

        result, message = SyncWorker(db_path, api_url, token, config=config)._sync_outbox_sales()
        if not result:
            raise RuntimeError(f"POS Central ส่งบิลไม่ผ่าน: {message}")

        session = init_db(f"sqlite:///{db_path}")
        try:
            sale = session.query(Sale).filter_by(local_sale_id=local_sale_id).one()
            if sale.status != "synced" or not sale.doc_number:
                raise RuntimeError(f"ERP ไม่ยืนยันใบเสร็จ: status={sale.status}, receipt={sale.doc_number}")
            receipt_no = sale.doc_number
        finally:
            session.close()

        print(f"PASS: POS Central → Laravel checkout: {receipt_no} · {product_sku} · {product_name} · ฿{total:.2f}")
        return 0
    finally:
        if window is not None:
            window.close()
        MainWindow.load_config = original_load_config
        receipt_dialog.ReceiptDialog = original_receipt_dialog
        for name, method in original_message_methods.items():
            setattr(QMessageBox, name, method)
        app.quit()


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as error:
        print(f"FAIL: {error}", file=sys.stderr)
        raise SystemExit(1)
