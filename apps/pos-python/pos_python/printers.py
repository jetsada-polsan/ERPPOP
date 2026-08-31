"""Windows printer discovery and spooler hand-off for the desktop POS.

Qt queries the same printer queues Windows exposes in Settings, so the cashier
selects a real installed queue instead of typing a driver or port by hand.
"""
from __future__ import annotations

import platform


def installed_printer_names(printer_info=None) -> list[str]:
    """Return installed Windows printer queue names; never invent a device."""
    if platform.system() != "Windows":
        return []

    if printer_info is None:
        try:
            from PySide6.QtPrintSupport import QPrinterInfo
        except ImportError:
            return []
        printer_info = QPrinterInfo

    try:
        names = printer_info.availablePrinterNames()
    except Exception:
        return []

    return sorted({str(name).strip() for name in names if str(name).strip()}, key=str.casefold)


def print_text_to_windows_queue(text: str, printer_name: str) -> None:
    """Hand receipt text to the selected Windows spooler queue.

    This must run from the already-created QApplication on the UI thread.
    """
    selected = printer_name.strip()
    if not selected:
        raise ValueError("ยังไม่ได้เลือกเครื่องพิมพ์ Windows")

    try:
        from PySide6.QtGui import QTextDocument
        from PySide6.QtPrintSupport import QPrinter
    except ImportError as error:
        raise RuntimeError("รุ่นนี้ยังไม่มี Qt PrintSupport") from error

    printer = QPrinter(QPrinter.PrinterMode.HighResolution)
    printer.setPrinterName(selected)
    if not printer.isValid():
        raise RuntimeError(f"Windows ไม่พบเครื่องพิมพ์: {selected}")

    document = QTextDocument()
    document.setPlainText(text)
    document.print(printer)
