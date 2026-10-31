from __future__ import annotations

import tempfile
import unittest
from pathlib import Path

from pos_python.database import connect
from pos_python.settings_service import PrinterProfile, ReceiptTemplate, SettingsService


class SettingsServiceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.service = SettingsService(self.db)

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def test_saves_local_terminal_settings(self) -> None:
        self.service.set_device_setting("terminal_id", "HQ-POS-01")
        self.assertEqual(self.service.get_device_setting("terminal_id"), "HQ-POS-01")

    def test_upserts_printer_profile_and_selects_it(self) -> None:
        profile = PrinterProfile("เคาน์เตอร์หลัก", "EPSON_ESC_POS", "USB", "USB001", 80)
        first = self.service.save_printer_profile(profile)
        second = self.service.save_printer_profile(PrinterProfile("เคาน์เตอร์หลัก", "GENERIC_ESC_POS", "USB", "USB002", 58, False))
        self.assertEqual(first, second)
        self.assertEqual(self.service.get_device_setting("active_printer_profile"), "เคาน์เตอร์หลัก")
        row = self.db.execute("SELECT paper_width_mm, open_drawer FROM printer_profiles WHERE id = ?", (first,)).fetchone()
        self.assertEqual((row["paper_width_mm"], row["open_drawer"]), (58, 0))

    def test_receipt_template_keeps_immutable_revisions(self) -> None:
        template = ReceiptTemplate("มาตรฐาน", 80, "POPSTAR", "ขอบคุณ")
        first = self.service.save_receipt_template(template)
        second = self.service.save_receipt_template(ReceiptTemplate("มาตรฐาน", 80, "POPSTAR ใหม่", "ขอบคุณ"))
        self.assertNotEqual(first, second)
        self.assertEqual(self.db.execute("SELECT count(*) FROM receipt_templates WHERE name = 'มาตรฐาน'").fetchone()[0], 2)
        active = self.service.active_receipt_template()
        self.assertEqual((active["revision"], active["header_text"]), (2, "POPSTAR ใหม่"))


if __name__ == "__main__":
    unittest.main()


class CashierPinModeTest(unittest.TestCase):
    def test_pin_is_required_unless_erp_says_selection(self) -> None:
        import tempfile
        from pathlib import Path

        from pos_python.database import connect
        from pos_python.services import PosService

        db = connect(Path(tempfile.mkdtemp()) / "pin.db")
        pos = PosService(db)
        self.assertTrue(pos.requires_cashier_pin())
        for mode, expected in (("pin", True), ("selection", False)):
            db.execute("INSERT OR REPLACE INTO device_settings (key, value, updated_at) VALUES ('cashier_login_mode', ?, datetime('now'))", (f'"{mode}"',))
            self.assertEqual(pos.requires_cashier_pin(), expected)


class ProductGridBarcodeSearchTest(unittest.TestCase):
    def test_grid_finds_a_product_by_its_13_digit_barcode(self) -> None:
        import tempfile
        from pathlib import Path

        from pos_python.database import connect
        from pos_python.order import product_grid

        db = connect(Path(tempfile.mkdtemp()) / "grid.db")
        columns = {r["name"]: r for r in db.execute("PRAGMA table_info(products)")}
        required = [n for n, r in columns.items() if r["notnull"] and r["dflt_value"] is None and not r["pk"]]
        values = {n: "x" for n in required} | {"sku": "100001", "name": "น้ำดื่ม", "active": 1}
        db.execute(f"INSERT INTO products ({','.join(values)}) VALUES ({','.join('?' * len(values))})", tuple(values.values()))
        product_id = db.execute("SELECT id FROM products").fetchone()["id"]
        db.execute("INSERT INTO product_barcodes (barcode, product_id, synced_at) VALUES ('8850123456789', ?, 'now')", (product_id,))

        self.assertEqual([r["sku"] for r in product_grid(db, search="8850123456789")], ["100001"])
        self.assertEqual(product_grid(db, search="9999999999999"), [])
