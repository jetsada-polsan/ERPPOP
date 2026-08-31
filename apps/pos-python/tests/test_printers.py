from __future__ import annotations

import unittest
from unittest.mock import patch

from pos_python.printers import installed_printer_names


class InstalledPrintersTest(unittest.TestCase):
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
