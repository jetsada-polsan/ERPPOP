from __future__ import annotations

import inspect
import unittest
from unittest.mock import patch

from pos_python import printers
from pos_python.printers import installed_printer_names


class InstalledPrintersTest(unittest.TestCase):
    def test_qt6_uses_the_python_safe_qtextdocument_print_method(self) -> None:
        source = inspect.getsource(printers.print_text_to_windows_queue)
        self.assertIn('getattr(document, "print_", None)', source)
        self.assertIn('getattr(document, "print", None)', source)
        self.assertIn('setDefaultFont(QFont("Courier New"', source)
        self.assertIn("QPrinterInfo.availablePrinters()", source)
        self.assertIn("QPrinter(printer_info, QPrinter.PrinterMode.HighResolution)", source)
        self.assertIn("setPageMargins(QMarginsF(0, 0, 0, 0), QPageLayout.Unit.Millimeter)", source)
        self.assertNotIn("QPrinter.Unit.Millimeter", source)
        self.assertIn("paper_width_mm", source)

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
