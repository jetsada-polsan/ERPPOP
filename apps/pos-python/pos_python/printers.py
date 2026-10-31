"""Windows printer discovery and spooler hand-off for the desktop POS.

Qt queries the same printer queues Windows exposes in Settings, so the cashier
selects a real installed queue instead of typing a driver or port by hand.
"""
from __future__ import annotations

import platform


def open_cash_drawer(printer_name: str) -> None:
    """Send the standard ESC/POS drawer pulse through the Windows queue.

    PyWin32 is optional on development machines; on Windows POS builds it is
    bundled with the hardware profile. Never claim success when the raw queue
    API is unavailable.
    """
    selected = printer_name.strip()
    if not selected:
        raise ValueError("ยังไม่ได้เลือกเครื่องพิมพ์สำหรับเปิดลิ้นชัก")
    if platform.system() != "Windows":
        raise RuntimeError("การเปิดลิ้นชักใช้ได้บน Windows POS เท่านั้น")
    try:
        import win32print
    except ImportError as error:
        raise RuntimeError("รุ่น POS นี้ยังไม่มีไลบรารี Windows printer (pywin32)") from error
    handle = win32print.OpenPrinter(selected)
    try:
        win32print.StartDocPrinter(handle, 1, ("PopCentral cash drawer", None, "RAW"))
        try:
            win32print.StartPagePrinter(handle)
            win32print.WritePrinter(handle, b"\x1b\x70\x00\x19\xfa")
            win32print.EndPagePrinter(handle)
        finally:
            win32print.EndDocPrinter(handle)
    finally:
        win32print.ClosePrinter(handle)


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
        from PySide6.QtCore import Qt
        from PySide6.QtGui import QColor, QFont, QPainter
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
    # เทอร์มอลไม่มีเกรย์สเกล ตัวบางจึงจาง และ Courier New ไม่มีอักษรไทย Qt สลับฟอนต์เองทำให้
    # เว้นวรรคจัดกึ่งกลางคลาดเคลื่อน จึงวาดเองด้วยตัวหนาแล้ววัดความกว้างจริงทีละบรรทัด
    # ไม่เรียก setPageMargins: PySide6 แต่ละรุ่นมี overload ไม่ตรงกันและพังก่อนถึง spooler
    painter = QPainter()
    if not painter.begin(printer):
        raise RuntimeError(f"เปิดงานพิมพ์ไม่ได้: {selected}")
    try:
        body = QFont("Tahoma", 10 if paper_width_mm == 80 else 9, QFont.Weight.Bold)
        code = QFont("Courier New", 8, QFont.Weight.Bold)
        page = printer.pageRect(QPrinter.Unit.DevicePixel)
        width = int(page.width())
        painter.setPen(QColor("black"))
        painter.setFont(body)
        y = 0
        for kind, left, right in classify_lines(text):
            painter.setFont(code if kind == "code" else body)
            metrics = painter.fontMetrics()
            height = metrics.height()
            if y + height > page.height():
                printer.newPage()
                y = 0
            if kind == "rule":
                pen = painter.pen()
                pen.setWidth(max(2, int(printer.resolution() / 100)))
                painter.setPen(pen)
                painter.drawLine(0, y + height // 2, width, y + height // 2)
            elif kind in ("center", "code"):
                shown = metrics.elidedText(left, Qt.TextElideMode.ElideRight, width)
                painter.drawText(max(0, (width - metrics.horizontalAdvance(shown)) // 2), y + metrics.ascent(), shown)
            else:
                room = width - metrics.horizontalAdvance(right) - metrics.averageCharWidth()
                painter.drawText(0, y + metrics.ascent(), metrics.elidedText(left, Qt.TextElideMode.ElideRight, max(0, room)))
                painter.drawText(width - metrics.horizontalAdvance(right), y + metrics.ascent(), right)
            y += height
    finally:
        painter.end()


def classify_lines(text: str) -> list[tuple[str, str, str]]:
    """แปลงบรรทัดใบเสร็จแบบเว้นวรรคเป็น (ชนิด, ซ้าย, ขวา) เพื่อวาดด้วยฟอนต์ความกว้างไม่คงที่"""
    rows: list[tuple[str, str, str]] = []
    for line in text.splitlines():
        stripped = line.strip()
        if not stripped:
            rows.append(("blank", "", ""))
        elif set(stripped) <= set("-="):
            rows.append(("rule", "", ""))
        elif set(stripped) & set("█▀▄"):
            rows.append(("code", line.rstrip(), ""))
        elif line[0] == " ":
            rows.append(("center", stripped, ""))
        elif "  " in stripped:
            left, _, right = stripped.rpartition("  ")
            rows.append(("columns", left.rstrip(), right.strip()))
        else:
            rows.append(("left", stripped, ""))
    return rows
