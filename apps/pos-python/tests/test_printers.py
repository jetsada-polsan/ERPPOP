from __future__ import annotations

import inspect
import unittest
from unittest.mock import patch

from pos_python import printers
from pos_python.printers import installed_printer_names


class InstalledPrintersTest(unittest.TestCase):
    def test_receipt_print_is_bold_and_measured_per_line(self) -> None:
        source = inspect.getsource(printers.print_text_to_windows_queue)
        self.assertIn("QFont.Weight.Bold", source)
        self.assertIn("horizontalAdvance", source)
        self.assertIn("QPrinterInfo.availablePrinters()", source)
        self.assertIn("QPrinter(printer_info, QPrinter.PrinterMode.HighResolution)", source)
        self.assertNotIn("setPageMargins(", source)
        self.assertIn("paper_width_mm", source)

    def test_weight_accepts_thai_digits_and_rejects_nonsense(self) -> None:
        from pos_python.ui import parse_weight

        self.assertEqual(parse_weight("๑.๒๕"), 1.25)
        with self.assertRaises(ValueError):
            parse_weight("0")

    def test_receipt_stays_inside_the_printable_width(self) -> None:
        source = inspect.getsource(printers.print_text_to_windows_queue)
        self.assertIn("printable_mm = 72 if paper_width_mm == 80 else 48", source)

    def test_receipt_lines_are_laid_out_by_kind(self) -> None:
        from pos_python.printers import classify_lines

        rows = classify_lines("      POPSTAR\n-----\nรวมสุทธิ          158.00\nหมายเหตุ")
        self.assertEqual(rows, [
            ("center", "POPSTAR", ""), ("rule", "", ""),
            ("columns", "รวมสุทธิ", "158.00"), ("left", "หมายเหตุ", ""),
        ])

    def test_windows_queues_are_sorted_and_deduplicated(self) -> None:
        class PrinterInfo:
            @staticmethod
            def availablePrinterNames():
                return ["Receipt 80mm", "", "Office Printer", "Receipt 80mm"]

        with patch("pos_python.printers.platform.system", return_value="Windows"):
            self.assertEqual(
                installed_printer_names(PrinterInfo),
                ["Office Printer", "Receipt 80mm"],
            )

    def test_non_windows_never_returns_a_fake_printer(self) -> None:
        with patch("pos_python.printers.platform.system", return_value="Darwin"):
            self.assertEqual(installed_printer_names(), [])


if __name__ == "__main__":
    unittest.main()
