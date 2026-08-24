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
