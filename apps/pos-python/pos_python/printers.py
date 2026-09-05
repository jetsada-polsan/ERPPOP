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


def print_text_to_windows_queue(text: str, printer_name: str, *, paper_width_mm: int = 80) -> None:
    """Hand receipt text to the selected Windows spooler queue.

    This must run from the already-created QApplication on the UI thread.
    """
    selected = printer_name.strip()
    if not selected:
        raise ValueError("ยังไม่ได้เลือกเครื่องพิมพ์ Windows")

    try:
        from PySide6.QtGui import QFont, QTextDocument, QTextOption
        from PySide6.QtPrintSupport import QPrinter, QPrinterInfo
    except ImportError as error:
        raise RuntimeError("รุ่นนี้ยังไม่มี Qt PrintSupport") from error

    printer_info = next(
        (info for info in QPrinterInfo.availablePrinters() if info.printerName().strip() == selected),
        None,
    )
    if printer_info is None:
        raise RuntimeError(f"Windows ไม่พบเครื่องพิมพ์: {selected}\nตรวจว่าเปิดเครื่องและติดตั้ง Driver แล้ว")

    # Constructing QPrinter from the installed queue is more reliable on Windows
    # than constructing an empty printer and assigning only its display name.
    printer = QPrinter(printer_info, QPrinter.PrinterMode.HighResolution)
    if not printer.isValid():
        raise RuntimeError(f"Windows ไม่พบเครื่องพิมพ์: {selected}")

    if paper_width_mm not in (58, 80):
        paper_width_mm = 80
    # Keep the text renderer deterministic. The Windows queue supplies the
    # continuous-paper height, while the document font controls readable 58/80mm
    # columns instead of falling back to a tiny proportional default.
    # Do not call QPagedPaintDevice.setPageMargins here. PySide6 exposes
    # incompatible overloads across supported Windows builds and can raise
    # before the job reaches the spooler. The installed thermal-driver profile
    # owns its physical margins; QTextDocument still removes its own margin.
    document = QTextDocument()
    document.setDocumentMargin(0)
    document.setDefaultFont(QFont("Courier New", 9 if paper_width_mm == 80 else 8))
    text_option = QTextOption()
    text_option.setWrapMode(QTextOption.WrapMode.NoWrap)
    document.setDefaultTextOption(text_option)
    document.setPlainText(text)
    # Qt exposes QTextDocument::print as print_ in PySide6 because print is a
    # Python keyword. Keep the fallback for bindings that expose the C++ name.
    send_to_printer = getattr(document, "print_", None) or getattr(document, "print", None)
    if send_to_printer is None:
        raise RuntimeError("PySide6 รุ่นนี้ไม่รองรับการส่งเอกสารไปเครื่องพิมพ์")
    send_to_printer(printer)
