"""หน้าจอขายแบบ Odoo — บิลอยู่ซ้าย ตารางสินค้าอยู่ขวา numpad แก้บรรทัดที่เลือก

ชั้นนี้ทำหน้าที่วาดและรับปุ่มเท่านั้น การคิดเงินทั้งหมดอยู่ใน order.py กับ
services.py ซึ่งมีเทสต์ครอบอยู่ เพราะตัวเลขที่ผิดในชั้นหน้าจอจะไม่มีอะไรจับได้
"""
from __future__ import annotations

import json
import sqlite3
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from string import Template

from .barcode import decode_scale_label, load_scale_profiles, scale_cart_line
from .api_client import LaravelApiError, LaravelPosClient
from .bootstrap import _cached_setting
from .mock_printer import active_paper_width, company_details, receipt_for
from .order import ALL_CATEGORIES, DISCOUNT, PRICE, QTY, Order, OrderLine, categories, product_grid
from .config import DeviceConfig, load_device_config, save_device_config
from .printers import installed_printer_names, print_text_to_windows_queue
from .promptpay import promptpay_payload, qr_matrix
from .services import PosService, money
from .settings_service import PrinterProfile, SettingsService

PALETTE = {
    # ชื่อและค่าตรงกับ token --erp-* ในระบบหลังบ้าน เพื่อให้ POS กับ ERP พูดภาษาสี
    # เดียวกัน เปลี่ยนธีมทีเดียวแล้วขยับตามกันทั้งสองฝั่ง
    "primary": "#1585c0",       # ฟ้า JET — เส้น accent, ตัวที่เลือกอยู่
    "primary_dark": "#0f4c75",  # ฟ้าเข้ม — แถบบน, หัวบิล
    "primary_ink": "#147db5",   # ใช้ตอนมีตัวหนังสือขาวทับ 4.54:1
    "primary_soft": "#eef4f9",
    "success": "#158662",       # เงินทอน — ความหมาย ไม่ใช่สีแบรนด์
    "warning": "#d98b00",
    "warning_soft": "#fdf4e3",
    "warning_ink": "#9c6400",   # บนพื้นเหลืองอ่อน 4.54:1
    "danger": "#c9212d",        # ยกเลิกบิล, เงินรับไม่พอ — ความหมาย ไม่ใช่สีแบรนด์
    "bg": "#f3f7fb",
    "surface": "#ffffff",
    "border": "#dbe7ef",
    "field": "#7199bb",         # ขอบช่องกรอกต้องเห็นได้ 3:1 ตาม WCAG 1.4.11
    "text": "#1d3b52",
    "muted": "#627481",
    "preview": "#c6d4e0",       # พื้นรองใบเสร็จ ให้กระดาษขาวเด้งออกมา
    "paper_ink": "#1a1a1a",     # หมึกบนกระดาษ ไม่ใช่สีจอ
}


def run_pairing_wizard(data_dir, app) -> bool:
    """Pair a new terminal before showing the cashier login screen."""
    try:
        from PySide6.QtCore import Qt
        from PySide6.QtWidgets import QDialog, QDialogButtonBox, QFormLayout, QLabel, QLineEdit, QMessageBox
    except ImportError as error:
        raise RuntimeError("ยังไม่ได้ติดตั้ง PySide6") from error

    if app is None:
        raise RuntimeError("POS ต้องเริ่ม QApplication จาก main.py ก่อนเปิดหน้าต่าง")
    dialog = QDialog()
    dialog.setWindowTitle("เชื่อมต่อ PopCentral POS")
    dialog.setWindowFlag(Qt.WindowCloseButtonHint, True)
    dialog.setMinimumWidth(420)
    dialog.resize(460, 260)
    form = QFormLayout(dialog)
    title = QLabel("ตั้งค่าเครื่องครั้งแรก")
    title.setStyleSheet("font-size:20px;font-weight:800")
    hint = QLabel("กรอกข้อมูลที่ IT ออกให้ เครื่องจะตรวจสอบสาขาและรหัส POS ก่อนบันทึก")
    hint.setWordWrap(True)
    form.addRow(title)
    form.addRow(hint)
    server = QLineEdit("http://27.254.143.219")
    server.setPlaceholderText("เช่น https://erp.example.com")
    token = QLineEdit()
    token.setPlaceholderText("Device token จาก ERP")
    token.setEchoMode(QLineEdit.Password)
    form.addRow("ที่อยู่ ERP", server)
    form.addRow("รหัสเชื่อมต่อเครื่อง", token)
    buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
    buttons.button(QDialogButtonBox.Ok).setText("ตรวจสอบและเริ่มใช้งาน")
    buttons.button(QDialogButtonBox.Cancel).setText("ปิด")
    form.addRow(buttons)

    def pair() -> None:
        url = server.text().strip().rstrip("/")
        device_token = token.text().strip()
        if not url or not device_token:
            QMessageBox.warning(dialog, "ข้อมูลไม่ครบ", "กรอกที่อยู่ ERP และรหัสเชื่อมต่อเครื่องให้ครบ")
            return
        try:
            profile = LaravelPosClient(url, device_token, allow_insecure=url.startswith("http://")).get("/api/pos/ping")
            if not profile.get("success", False):
                raise RuntimeError(profile.get("message", "ERP ไม่ยืนยันเครื่องนี้"))
            save_device_config(data_dir, DeviceConfig(url, device_token, url.startswith("http://")))
            QMessageBox.information(
                dialog,
                "เชื่อมต่อสำเร็จ",
                f"สาขา: {profile.get('branch_name', '-')}\n"
                f"เครื่อง: {(profile.get('device') or {}).get('terminal_code', '-')}\n\n"
                "กำลังเปิด POS และ sync ข้อมูลครั้งแรก",
            )
            dialog.accept()
        except Exception as error:
            QMessageBox.warning(dialog, "เชื่อมต่อไม่สำเร็จ", str(error))

    buttons.accepted.connect(pair)
    buttons.rejected.connect(dialog.reject)
    result = dialog.exec() == QDialog.Accepted
    app.processEvents()
    return result

# QSS ใช้ปีกกาเป็นไวยากรณ์ เลยแทนค่าด้วย $name แทน .format()
_STYLE_TEMPLATE = Template("""
/* สีและระยะตามภาพร่างที่อนุมัติแล้ว — โทน JET ชุดเดียวกับ ERP บนพื้นเทาอ่อน */
QMainWindow, QDialog, QWidget { background: $bg; color: $text; font-family: 'Noto Sans Thai','Leelawadee UI','Sarabun','Tahoma'; font-size: 14px; }

/* ป้ายที่วางบนแถบสีต้องโปร่ง ไม่งั้นกินสีพื้นแอปมาเป็นแผ่นขาวทับแถบ */
#brandBar { background: $primary_dark; border-bottom: 3px solid $primary; }
#brandName, #brandMark, #brandRight,
#totalLabel, #vatLine, #grandTotal { background: transparent; }
#brandName { color: $surface; font-size: 18px; font-weight: 800; padding: 14px 0; }
#brandMark { color: $surface; border: 2px solid $surface; border-radius: 6px; padding: 5px 9px; font-weight: 800; }
#brandRight { color: $surface; font-size: 13px; }
QToolButton#dialogClose { color: $surface; background: transparent; border: 0; padding: 3px 10px; font-size: 24px; font-weight: 800; }
QToolButton#dialogClose:hover { background: rgba(255,255,255,.16); border-radius: 6px; }

#card { background: $surface; border: 1px solid $border; border-radius: 7px; }
#cardTitle { font-size: 19px; font-weight: 800; color: $primary_dark; }
#cardHint, #muted { color: $muted; font-size: 13px; }
#note { border-left: 4px solid $warning; background: $warning_soft; color: $warning_ink; font-size: 13px; padding: 12px; }

/* เมนูซ้ายของหน้าตั้งค่า — ตัวที่เลือกอยู่มีขีดฟ้าหน้าเหมือนในภาพร่าง */
#navItem { background: transparent; border: 0; border-left: 4px solid transparent; border-radius: 0;
           text-align: left; padding: 12px 14px; color: $text; font-size: 14.5px; }
#navItem:hover { background: $primary_soft; }
#navItem:checked { background: $primary_soft; color: $primary_dark; border-left: 4px solid $primary; font-weight: 700; }

QPushButton { background: $surface; border: 1px solid $border; border-radius: 7px; padding: 10px 14px; font-size: 14.5px; }
QPushButton:hover { background: $primary_soft; border-color: $primary; }
QPushButton:checked { background: $primary_ink; color: $surface; border-color: $primary_ink; }
QPushButton#primary, QPushButton#payBtn { background: $primary_ink; color: $surface; border-color: $primary_ink; font-weight: 700; }
QPushButton#primary:hover, QPushButton#payBtn:hover { background: $primary_dark; border-color: $primary_dark; }
QPushButton#payBtn { font-size: 18px; padding: 16px; }
QPushButton#voidBtn { color: $danger; }
QToolButton#tile {
    text-align: left;
    padding: 12px;
    background: $surface;
    border: 1px solid $border;
    border-radius: 9px;
    font-size: 14px;
    font-weight: 650;
}
QToolButton#tile:hover { background: $primary_soft; border-color: $primary; }
QScrollArea#productScroll { background: transparent; border: 0; }

QLineEdit, QComboBox { background: $surface; border: 1px solid $field; border-radius: 7px; padding: 9px 11px; font-size: 14.5px; }
QLineEdit:focus, QComboBox:focus { border-color: $primary; }

/* จอ POS 14-15 นิ้วมักเป็น 1366x768 — ลดเฉพาะ chrome ของหน้าจอ
   เพื่อเก็บพื้นที่ไว้ให้รายการสินค้าและบิล ไม่เปลี่ยนขนาดตัวเลขทางธุรกิจ */
QMainWindow[compact="true"] #brandName { font-size: 15px; padding: 8px 0; }
QMainWindow[compact="true"] #brandRight { font-size: 11px; }
QMainWindow[compact="true"] QPushButton { padding: 6px 8px; font-size: 13px; }
QMainWindow[compact="true"] QPushButton#payBtn { font-size: 15px; padding: 10px 8px; }
QMainWindow[compact="true"] QLineEdit, QComboBox { padding: 6px 8px; font-size: 13px; }
QMainWindow[compact="true"] QToolButton#tile { padding: 8px; font-size: 12.5px; }
QMainWindow[compact="true"] #grandTotal { font-size: 25px; }

#orderPanel { background: $surface; border-right: 1px solid $border; }
#orderHead { background: $primary_dark; color: $surface; padding: 12px 16px; font-weight: 700; }
#orderHead QLabel { background: transparent; color: $surface; font-weight: 700; }
QPushButton#headerAction { background: transparent; color: $surface; border-color: rgba(255,255,255,0.45); padding: 5px 10px; }
QPushButton#headerAction:hover { background: rgba(255,255,255,0.12); border-color: $surface; }
#totalBox { background: $text; color: $surface; padding: 14px 16px; }
#totalBox QLabel { background: transparent; }
#grandTotal { font-size: 30px; font-weight: 900; color: $surface; }
#totalLabel, #vatLine { color: $preview; font-size: 13px; }

QTableWidget { background: $surface; border: 0; }
QHeaderView::section { background: $primary_soft; border: 0; border-bottom: 1px solid $border; padding: 8px; font-weight: 700; }

/* กระดาษใบเสร็จวางบนพื้นเทาเหมือนวางบนโต๊ะ */
#receiptBg { background: $preview; border-radius: 6px; }
#receiptPaper { background: $surface; padding: 20px; font-family: 'Noto Sans Thai','Leelawadee UI','Tahoma','Courier New'; font-size: 12px; color: $paper_ink; }
#statusBar { color: $muted; font-size: 12.5px; padding: 6px 16px; }

#shiftAmount { font-size: 25px; font-weight: 800; padding: 12px 14px; }
#shiftSummary { background: $primary_soft; border: 1px solid $border; border-radius: 7px; padding: 10px 12px; font-size: 15px; font-weight: 700; }
#shiftKeypad QPushButton { min-height: 42px; font-size: 18px; font-weight: 700; }
""")

STYLE = _STYLE_TEMPLATE.substitute(PALETTE)

SECTION_HINTS = {
    "ข้อมูลเครื่อง POS": "รหัสเครื่องและสาขาที่ผูกอยู่ ใช้ตอนส่งบิลขึ้น ERP",
    "เครื่องพิมพ์และใบเสร็จ": "ตั้งค่าเครื่องพิมพ์และดูตัวอย่างใบเสร็จก่อนบันทึก",
    "เครื่องชั่ง / Barcode": "รูปแบบป้ายมาจาก ERP เครื่องนี้ไม่เดารูปแบบเอง",
    "การ Sync และ API": "สถานะคิวบิลและค่าที่ sync มาจาก ERP",
    "สำรองและกู้คืน SQLite": "สำรองฐานข้อมูลในเครื่องและกู้คืนเมื่อจำเป็น",
    "ประวัติการพิมพ์ / Queue": "งานพิมพ์ล่าสุดและจำนวนครั้งที่พยายามส่ง",
}

NUMPAD_KEYS = [
    ["7", "8", "9"],
    ["4", "5", "6"],
    ["1", "2", "3"],
    [".", "0", "backspace"],
]

PRODUCT_TILE_COLUMNS = 4


def run_ui(service: PosService, online=None, data_dir=None, app=None):
    # เครื่องที่ผูก ERP แล้วใช้ branch/terminal จริงจาก ping; เครื่อง demo ใช้ค่าเดิม
    branch_id = int(online.branch_id) if (online is not None and online.branch_id) else 1
    terminal_id = (online.terminal_id if (online is not None and online.terminal_id) else "PY-TEST-01")
    layout_config = _cached_layout(service.db)
    try:
        from PySide6.QtCore import QTimer, QSize, Qt
        from PySide6.QtGui import QColor, QFont, QFontDatabase, QImage, QKeySequence, QPainter, QPixmap, QShortcut
        from PySide6.QtWidgets import (
            QButtonGroup, QCheckBox, QComboBox, QDialog, QDialogButtonBox, QFormLayout, QGridLayout,
            QHBoxLayout, QHeaderView, QLabel, QLineEdit, QMainWindow, QMessageBox, QPushButton,
            QScrollArea, QSplitter, QTableWidget, QTableWidgetItem, QToolButton, QVBoxLayout, QWidget,
        )
    except ImportError as error:
        raise RuntimeError("ยังไม่ได้ติดตั้ง PySide6: python3 -m pip install -r requirements.txt") from error

    # Prefer a modern Thai-capable Windows font, but never assume it is installed.
    # Qt will otherwise fall back inconsistently between the POS screen and dialogs.
    available_fonts = set(QFontDatabase.families())
    ui_font_family = next(
        (family for family in ("Noto Sans Thai", "Leelawadee UI", "Sarabun", "Tahoma", "Arial")
         if family in available_fonts),
        "Tahoma",
    )
    app.setFont(QFont(ui_font_family, 14))

    def _qr_pixmap(payload: str, size: int) -> QPixmap:
        matrix = qr_matrix(payload, border=3)
        modules = len(matrix)
        scale = max(1, size // modules)
        actual = modules * scale
        image = QImage(actual, actual, QImage.Format_RGB32)
        image.fill(QColor("white"))
        painter = QPainter(image)
        painter.setPen(Qt.NoPen)
        painter.setBrush(QColor("black"))
        for row, values in enumerate(matrix):
            for column, filled in enumerate(values):
                if filled:
                    painter.drawRect(column * scale, row * scale, scale, scale)
        painter.end()
        return QPixmap.fromImage(image)

    class LoginDialog(QDialog):
        def __init__(self):
            super().__init__()
            self.cashier = None
            self.setWindowTitle("PopCentral POS — ยืนยันก่อนเริ่มขาย")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setWindowFlag(Qt.WindowStaysOnTopHint, True)
            self.setWindowModality(Qt.WindowModal)
            self.setMinimumWidth(420)
            form = QFormLayout(self)
            hint = QLabel(
                "ดูสินค้าและรายงานได้โดยไม่ต้องล็อกอิน "
                "กรอกรหัสแคชเชียร์และ PIN เมื่อต้องการเริ่มขาย"
            )
            hint.setWordWrap(True)
            self.code = QLineEdit()
            self.code.setPlaceholderText("รหัสแคชเชียร์ หรือ username ERP")
            self.cashier_select = QComboBox()
            self.cashier_select.addItem("เลือกชื่อคนขาย", None)
            device_user_id = _cached_setting(service.db, "device_user_id")
            cashier_query = """SELECT code, name, server_id, user_id FROM local_cashiers
                              WHERE active = 1 AND user_id IS NOT NULL AND revoked_at IS NULL"""
            cashier_params: tuple = ()
            if device_user_id is not None:
                cashier_query += " AND user_id = ?"
                cashier_params = (int(device_user_id),)
            cashier_query += " ORDER BY name, code"
            cashiers = service.db.execute(cashier_query, cashier_params).fetchall()
            for cashier in cashiers:
                self.cashier_select.addItem(
                    f"{cashier['name']}  ·  {cashier['code']}",
                    {"code": cashier["code"], "server_id": cashier["server_id"]},
                )
            self.cashier_select.currentIndexChanged.connect(lambda _: self._select_cashier())
            self.pin = QLineEdit()
            self.pin.setEchoMode(QLineEdit.Password)
            self.pin.setPlaceholderText("แตะ PIN")
            self.pin.returnPressed.connect(self.login)
            self.connection_status = QLabel(self._offline_notice())
            self.connection_status.setWordWrap(True)
            submit = QPushButton("ยืนยันและเริ่มขาย")
            submit.setObjectName("primary")
            submit.clicked.connect(self.login)
            maintenance = QPushButton("IT Maintenance")
            maintenance.clicked.connect(self.open_maintenance)
            override = QPushButton("ผู้จัดการช่วยกู้ PIN")
            override.clicked.connect(self.manager_override)
            self.passwordless = bool(
                online is not None and online.online
                and _cached_setting(service.db, "cashier_login_mode") == "selection"
                and len(cashiers) == 1
            )
            if self.passwordless:
                hint.setText("เครื่องนี้ผูกกับผู้ใช้ POS แล้ว · กดเริ่มขายได้เลย")
                submit.setText("เริ่มขาย")
                self.pin.hide()
                override.hide()
            form.addRow(hint)
            if cashiers:
                form.addRow("คนขาย", self.cashier_select)
            else:
                form.addRow("รหัสแคชเชียร์", self.code)
            if not self.passwordless:
                form.addRow("PIN", self.pin)
            pin_pad = QWidget()
            pin_grid = QGridLayout(pin_pad)
            pin_grid.setContentsMargins(0, 0, 0, 0)
            for index, key in enumerate(["1", "2", "3", "4", "5", "6", "7", "8", "9", "ล้าง", "0", "⌫"]):
                button = QPushButton(key)
                button.setMinimumHeight(42)
                button.clicked.connect(lambda _, value=key: self._press_pin(value))
                pin_grid.addWidget(button, index // 3, index % 3)
            form.addRow("", pin_pad)
            form.addRow(self.connection_status)
            form.addRow(submit)
            form.addRow(maintenance)
            form.addRow(override)

        def _select_cashier(self) -> None:
            selected = self.cashier_select.currentData() or {}
            code = selected.get("code") if isinstance(selected, dict) else selected
            self.code.setText(str(code or ""))
            if code and not self.passwordless:
                self.pin.setFocus()

        def _press_pin(self, key: str) -> None:
            if key == "ล้าง":
                self.pin.clear()
            elif key == "⌫":
                self.pin.backspace()
            else:
                self.pin.insert(key)

        def open_settings(self) -> None:
            dialog = SettingsDialog(self, None)
            dialog.exec()

        def _offline_notice(self) -> str:
            last = service.db.execute(
                "SELECT max(last_synced_at) FROM local_cashiers WHERE last_synced_at IS NOT NULL"
            ).fetchone()[0]
            if online is not None and online.online:
                return f"เชื่อมต่อ ERP แล้ว · ข้อมูลแคชเชียร์ sync ล่าสุด {last or 'กำลัง sync'}"
            return (
                f"Offline Mode · Cashier data last synced at {last or 'ยังไม่มีข้อมูล'}\n"
                "PIN ใหม่จะใช้ได้หลัง POS sync · Offline login expired, please reconnect to server"
            )

        def _offline_login(self) -> bool:
            result = service.login_offline(self.code.text().strip(), self.pin.text())
            if not result.success:
                QMessageBox.warning(self, "เข้าสู่ระบบออฟไลน์ไม่สำเร็จ", result.reason or "ไม่พบผู้ใช้หรือ PIN ไม่ถูกต้อง")
                return False
            self.cashier = result.cashier
            return True

        def _online_login(self) -> bool:
            pin = self.pin.text().strip()
            code = self.code.text().strip()
            try:
                # ดึงสถานะแคชเชียร์ล่าสุดก่อนยืนยันเสมอ แต่ห้ามลบ verifier เก่า
                # เพราะถ้าเน็ตหลุดกลางทางยังต้อง fallback ไป SQLite ได้ทันที.
                online.provisioning.pull_cashiers(online.branch_id)
                selected = self.cashier_select.currentData() or {}
                selected_server_id = selected.get("server_id") if isinstance(selected, dict) else None
                if self.passwordless:
                    if not selected_server_id:
                        QMessageBox.warning(self, "ยังไม่พร้อมเริ่มขาย", "ยังไม่พบผู้ใช้ที่ผูกกับเครื่องนี้ กรุณา Sync จาก ERP")
                        return False
                    result = online.provisioning.online_cashier_login(
                        None, cashier_code=code, cashier_server_id=int(selected_server_id)
                    )
                else:
                    result = online.provisioning.online_cashier_login(pin, cashier_code=code)
                if result.get("selection_required"):
                    # PIN กลางตรงหลายคน — เลือกด้วยรหัสแคชเชียร์ที่กรอก
                    match = next((c for c in result["cashiers"] if str(c.get("code")) == code), None)
                    if not match:
                        QMessageBox.warning(self, "เลือกพนักงาน", "PIN นี้มีหลายคน กรุณากรอกรหัสแคชเชียร์ของคุณด้วย")
                        return False
                    result = online.provisioning.online_cashier_login(
                        pin, cashier_code=code, cashier_server_id=int(match["id"])
                    )
                if result.get("must_change_pin"):
                    result = self._force_pin_change(result, pin)
                    if not result:
                        return False
            except Exception as error:
                if isinstance(error, LaravelApiError) and "network:" in str(error):
                    online.online = False
                    self.connection_status.setText(self._offline_notice())
                    return self._offline_login()
                service.record_auth_event(code, "online_login", False, str(error)[:500])
                QMessageBox.critical(self, "เข้าสู่ระบบไม่สำเร็จ", str(error))
                return False
            self.cashier = service.db.execute(
                "SELECT * FROM local_cashiers WHERE id = ?", (result["local_cashier_id"],)
            ).fetchone()
            service.record_auth_event(code, "online_login", self.cashier is not None,
                                      None if self.cashier is not None else "ไม่พบข้อมูลใน SQLite")
            online.worker.wake()
            return self.cashier is not None

        def _force_pin_change(self, result: dict, current_pin: str) -> dict | None:
            cashier = result.get("cashier") or {}
            code = str(cashier.get("code") or self.code.text().strip())
            dialog = QDialog(self)
            dialog.setWindowTitle("ตั้ง PIN POS ใหม่")
            dialog.setWindowFlag(Qt.WindowCloseButtonHint, True)
            dialog.setMinimumWidth(420)
            form = QFormLayout(dialog)
            hint = QLabel("PIN นี้เป็นรหัสชั่วคราวจากผู้ดูแล ต้องเปลี่ยนเป็น PIN ของคุณก่อนเข้า POS")
            hint.setWordWrap(True)
            new_pin = QLineEdit()
            confirm_pin = QLineEdit()
            new_pin.setEchoMode(QLineEdit.Password)
            confirm_pin.setEchoMode(QLineEdit.Password)
            new_pin.setPlaceholderText("ตัวเลข 4-20 หลัก")
            confirm_pin.setPlaceholderText("กรอกซ้ำ")
            buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
            buttons.accepted.connect(dialog.accept)
            buttons.rejected.connect(dialog.reject)
            form.addRow(hint)
            form.addRow("PIN ใหม่", new_pin)
            form.addRow("ยืนยัน PIN ใหม่", confirm_pin)
            form.addRow(buttons)

            if dialog.exec() != QDialog.Accepted:
                return None
            new_value = new_pin.text().strip()
            if new_value != confirm_pin.text().strip():
                QMessageBox.warning(self, "PIN ไม่ตรงกัน", "กรุณากรอก PIN ใหม่ให้ตรงกัน")
                return None
            if not new_value.isdigit() or len(new_value) < 4:
                QMessageBox.warning(self, "PIN ไม่ถูกต้อง", "PIN ต้องเป็นตัวเลขอย่างน้อย 4 หลัก")
                return None
            changed = online.provisioning.change_cashier_pin(code, current_pin, new_value)
            QMessageBox.information(self, "เปลี่ยน PIN แล้ว", "ใช้ PIN ใหม่ของคุณสำหรับเข้า POS ครั้งถัดไป")
            return {"selection_required": False, **changed, "must_change_pin": False}

        def login(self):
            if not self.code.text().strip():
                QMessageBox.information(self, "เลือกคนขาย", "เลือกชื่อคนขายก่อนกรอก PIN")
                return
            if online is not None and online.online:
                if self._online_login():
                    self.accept()
                return
            if self._offline_login():
                self.accept()

        def open_maintenance(self) -> None:
            if not service.has_local_it_pin():
                if online is None or not online.online or AdminAuthDialog(self).exec() != QDialog.Accepted:
                    QMessageBox.warning(self, "ต้องตั้ง Local IT PIN", "เชื่อม ERP และยืนยันผู้ดูแลเพื่อตั้ง Local IT PIN ครั้งแรก")
                    return
                pin, ok = self._ask_pin("ตั้ง Local IT PIN", "PIN สำหรับ IT เครื่องนี้ (6-20 หลัก)")
                if not ok:
                    return
                try:
                    service.set_local_it_pin(pin)
                except ValueError as error:
                    QMessageBox.warning(self, "PIN ไม่ถูกต้อง", str(error))
                    return
            pin, ok = self._ask_pin("IT Maintenance", "กรอก Local IT PIN")
            if ok and service.verify_local_it_pin(pin):
                SettingsDialog(self, None).exec()
            elif ok:
                QMessageBox.warning(self, "ยืนยันไม่สำเร็จ", "Local IT PIN ไม่ถูกต้อง")

        def manager_override(self) -> None:
            dialog = QDialog(self)
            dialog.setWindowTitle("ผู้จัดการช่วยกู้ PIN")
            dialog.setWindowFlag(Qt.WindowCloseButtonHint, True)
            dialog.setMinimumWidth(420)
            form = QFormLayout(dialog)
            manager_code, manager_pin, cashier_code, temporary_pin = QLineEdit(), QLineEdit(), QLineEdit(), QLineEdit()
            manager_pin.setEchoMode(QLineEdit.Password)
            temporary_pin.setEchoMode(QLineEdit.Password)
            temporary_pin.setPlaceholderText("PIN ชั่วคราว 4-20 หลัก · ใช้ได้สูงสุด 4 ชั่วโมง")
            form.addRow("รหัสผู้จัดการ", manager_code)
            form.addRow("PIN ผู้จัดการ", manager_pin)
            form.addRow("รหัสแคชเชียร์", cashier_code)
            form.addRow("PIN ชั่วคราว", temporary_pin)
            buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
            buttons.accepted.connect(dialog.accept)
            buttons.rejected.connect(dialog.reject)
            form.addRow(buttons)
            if dialog.exec() != QDialog.Accepted:
                return
            result = service.manager_override_reset(
                manager_code=manager_code.text().strip(), manager_pin=manager_pin.text(),
                cashier_code=cashier_code.text().strip(), temporary_pin=temporary_pin.text(),
            )
            if not result.success:
                QMessageBox.warning(self, "กู้ PIN ไม่สำเร็จ", result.reason or "ไม่สามารถทำรายการได้")
                return
            QMessageBox.information(self, "ออก PIN ชั่วคราวแล้ว", "ให้แคชเชียร์เข้า POS ด้วย PIN นี้ก่อนหมดอายุ\nรายการจะถูกส่งเป็น audit เมื่อเชื่อม ERP")

        def _ask_pin(self, title: str, label: str) -> tuple[str, bool]:
            from PySide6.QtWidgets import QInputDialog
            return QInputDialog.getText(self, title, label, QLineEdit.Password)

    class OpeningShiftDialog(QDialog):
        """หน้าต่างเปิดกะที่ไม่ถูก On-Screen Keyboard บัง และกดยอดเงินทอนได้ง่าย"""

        def __init__(self, parent):
            super().__init__(parent)
            self.opening_cash = Decimal("0.00")
            self.setWindowTitle("เปิดกะขาย")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setWindowFlag(Qt.WindowStaysOnTopHint, True)
            self.setWindowModality(Qt.WindowModal)
            self.setMinimumWidth(390)
            self.resize(420, 520)

            layout = QVBoxLayout(self)
            title = QLabel("เงินทอนตั้งต้น")
            title.setStyleSheet("font-size:20px;font-weight:800")
            hint = QLabel("ใส่เงินสดที่เตรียมไว้ในลิ้นชักก่อนเริ่มขาย")
            hint.setObjectName("cardHint")
            hint.setWordWrap(True)
            layout.addWidget(title)
            layout.addWidget(hint)

            self.amount = QLineEdit()
            self.amount.setObjectName("shiftAmount")
            self.amount.setAlignment(Qt.AlignRight)
            self.amount.setPlaceholderText("0.00")
            self.amount.setInputMethodHints(Qt.ImhFormattedNumbersOnly)
            self.amount.textChanged.connect(self.refresh_summary)
            self.amount.returnPressed.connect(self.accept_value)
            layout.addWidget(self.amount)

            self.summary = QLabel("เงินทอนตั้งต้น 0.00 บาท")
            self.summary.setObjectName("shiftSummary")
            self.summary.setAlignment(Qt.AlignCenter)
            layout.addWidget(self.summary)

            keypad = QWidget()
            keypad.setObjectName("shiftKeypad")
            grid = QGridLayout(keypad)
            grid.setContentsMargins(0, 0, 0, 0)
            grid.setSpacing(7)
            keys = [["7", "8", "9"], ["4", "5", "6"], ["1", "2", "3"], ["ล้าง", "0", "⌫"]]
            for row, values in enumerate(keys):
                for column, key in enumerate(values):
                    button = QPushButton(key)
                    button.clicked.connect(lambda _, value=key: self.press(value))
                    grid.addWidget(button, row, column)
            decimal = QPushButton("จุดทศนิยม")
            decimal.clicked.connect(lambda: self.press("."))
            grid.addWidget(decimal, 4, 0, 1, 3)
            layout.addWidget(keypad)

            buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
            buttons.button(QDialogButtonBox.Ok).setText("เปิดกะขาย")
            buttons.button(QDialogButtonBox.Cancel).setText("ยกเลิก")
            buttons.button(QDialogButtonBox.Ok).setObjectName("primary")
            buttons.accepted.connect(self.accept_value)
            buttons.rejected.connect(self.reject)
            layout.addWidget(buttons)

        def press(self, key: str) -> None:
            current = self.amount.text()
            if key == "ล้าง":
                self.amount.clear()
            elif key == "⌫":
                self.amount.backspace()
            elif key == ".":
                if "." not in current:
                    self.amount.insert("." if current else "0.")
            elif current in ("0", "0.00"):
                self.amount.setText(key)
            else:
                self.amount.insert(key)
            self.amount.setFocus()

        def value(self) -> Decimal:
            return money(self.amount.text().strip() or "0")

        def refresh_summary(self) -> None:
            try:
                value = self.value()
                self.summary.setText(f"เงินทอนตั้งต้น {value:,.2f} บาท")
            except (InvalidOperation, ValueError):
                self.summary.setText("กรอกจำนวนเงินเป็นตัวเลข เช่น 500 หรือ 500.00")

        def accept_value(self) -> None:
            try:
                value = self.value()
            except (InvalidOperation, ValueError):
                QMessageBox.warning(self, "จำนวนเงินไม่ถูกต้อง", "กรอกจำนวนเงินเป็นตัวเลขเท่านั้น")
                self.amount.setFocus()
                return
            if value < 0:
                QMessageBox.warning(self, "จำนวนเงินไม่ถูกต้อง", "เงินทอนตั้งต้นต้องไม่ติดลบ")
                return
            self.opening_cash = value
            self.accept()

    class ExistingShiftDialog(QDialog):
        """Make an open shift explicit instead of silently skipping opening cash."""

        def __init__(self, parent, shift):
            super().__init__(parent)
            self.close_existing = False
            self.setWindowTitle("พบกะที่ยังเปิดอยู่")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setWindowFlag(Qt.WindowStaysOnTopHint, True)
            self.setWindowModality(Qt.WindowModal)
            self.setMinimumWidth(420)

            layout = QVBoxLayout(self)
            title = QLabel("เครื่องนี้มีกะเดิมที่ยังไม่ได้ปิด")
            title.setStyleSheet("font-size:19px;font-weight:800")
            detail = QLabel(
                f"เปิดเมื่อ {str(shift['opened_at'])[:16].replace('T', ' ')}\n"
                f"เงินทอนต้นกะ {money(shift['opening_cash']):,.2f} บาท"
            )
            detail.setObjectName("shiftSummary")
            detail.setAlignment(Qt.AlignCenter)
            note = QLabel("ทำกะเดิมต่อได้ทันที หรือปิดกะเดิมก่อนเพื่อใส่เงินทอนตั้งต้นยอดใหม่")
            note.setObjectName("cardHint")
            note.setWordWrap(True)
            layout.addWidget(title)
            layout.addWidget(detail)
            layout.addWidget(note)

            actions = QHBoxLayout()
            cancel = QPushButton("ยกเลิก")
            close_old = QPushButton("ปิดกะเดิม")
            resume = QPushButton("ทำกะเดิมต่อ")
            resume.setObjectName("primary")
            cancel.clicked.connect(self.reject)
            close_old.clicked.connect(self.request_close)
            resume.clicked.connect(self.accept)
            actions.addWidget(cancel)
            actions.addWidget(close_old)
            actions.addWidget(resume)
            layout.addLayout(actions)

        def request_close(self) -> None:
            self.close_existing = True
            self.accept()

    class PaymentDialog(QDialog):
        """หน้าชำระเงิน — เงินทอนคำนวณสด ๆ ระหว่างพิมพ์ ไม่ต้องคิดในหัว"""

        def __init__(self, parent, order: Order):
            super().__init__(parent)
            self.order = order
            self.payment_method = "cash"
            self.qr_payload: str | None = None
            self.qr_config = _cached_json_setting(service.db, "qr_payment") or {}
            self.setWindowTitle("รับชำระเงิน")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setMinimumWidth(440)
            self.resize(480, 560)
            layout = QVBoxLayout(self)

            self.due = QLabel(f"ยอดชำระ {order.grand_total():,.2f} บาท")
            self.due.setStyleSheet("font-size:22px;font-weight:800;color:%s;" % PALETTE["text"])
            layout.addWidget(self.due)

            methods = QHBoxLayout()
            self.cash_method = QPushButton("เงินสด")
            self.cash_method.setCheckable(True)
            self.cash_method.setChecked(True)
            self.cash_method.clicked.connect(lambda: self.set_payment_method("cash"))
            self.transfer_method = QPushButton("โอน / QR")
            self.transfer_method.setCheckable(True)
            self.transfer_method.clicked.connect(lambda: self.set_payment_method("transfer"))
            method_group = QButtonGroup(self)
            method_group.setExclusive(True)
            method_group.addButton(self.cash_method)
            method_group.addButton(self.transfer_method)
            methods.addWidget(self.cash_method)
            methods.addWidget(self.transfer_method)
            layout.addLayout(methods)

            self.cash_label = QLabel("เงินที่รับมา")
            self.amount = QLineEdit(str(order.grand_total()))
            self.amount.textChanged.connect(self.refresh_change)
            layout.addWidget(self.cash_label)
            layout.addWidget(self.amount)

            self.quick_host = QWidget()
            quick = QHBoxLayout(self.quick_host)
            quick.setContentsMargins(0, 0, 0, 0)
            for note in (100, 500, 1000):
                button = QPushButton(f"{note:,}")
                button.clicked.connect(lambda _, value=note: self.amount.setText(str(value)))
                quick.addWidget(button)
            exact = QPushButton("พอดี")
            exact.clicked.connect(lambda: self.amount.setText(str(order.grand_total())))
            quick.addWidget(exact)
            layout.addWidget(self.quick_host)

            self.change = QLabel()
            self.change.setStyleSheet("font-size:18px;font-weight:700;color:%s;" % PALETTE["success"])
            layout.addWidget(self.change)

            self.qr_box = QWidget()
            qr_layout = QVBoxLayout(self.qr_box)
            self.qr_account = QLabel()
            self.qr_account.setAlignment(Qt.AlignCenter)
            self.qr_account.setWordWrap(True)
            self.qr_image = QLabel()
            self.qr_image.setAlignment(Qt.AlignCenter)
            self.transfer_confirmed = QCheckBox("ตรวจสอบแล้วว่าเงินเข้าบัญชีครบตามยอด")
            self.transfer_confirmed.setStyleSheet("font-size:16px;font-weight:700")
            self.transfer_confirmed.toggled.connect(self._confirm_transfer_checkbox)
            qr_layout.addWidget(self.qr_account)
            qr_layout.addWidget(self.qr_image)
            qr_layout.addWidget(self.transfer_confirmed)
            self.qr_box.hide()
            layout.addWidget(self.qr_box)

            buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
            buttons.accepted.connect(self.confirm)
            buttons.rejected.connect(self.reject)
            layout.addWidget(buttons)
            self.refresh_change()

        def _confirm_transfer_checkbox(self, checked: bool) -> None:
            """การยืนยันเงินเข้าเป็น action เดียวกับปุ่มออกบิล ไม่ต้องกดซ้ำ"""
            if checked and self.payment_method == "transfer":
                self.confirm()

        def set_payment_method(self, method: str) -> None:
            if method == "transfer" and not self.qr_config.get("merchant_ref"):
                QMessageBox.warning(self, "ยังไม่ได้ตั้ง QR", "ให้ IT ตั้งบัญชี PromptPay ใน ERP แล้ว Sync เครื่อง POS ก่อน")
                self.cash_method.setChecked(True)
                return
            self.payment_method = method
            transfer = method == "transfer"
            self.cash_label.setVisible(not transfer)
            self.amount.setVisible(not transfer)
            self.quick_host.setVisible(not transfer)
            self.qr_box.setVisible(transfer)
            if transfer:
                try:
                    self.qr_payload = promptpay_payload(
                        str(self.qr_config["merchant_ref"]), self.order.grand_total()
                    )
                    self.qr_image.setPixmap(_qr_pixmap(self.qr_payload, 250))
                except (ValueError, RuntimeError) as error:
                    QMessageBox.warning(self, "สร้าง QR ไม่สำเร็จ", str(error))
                    self.payment_method = "cash"
                    self.cash_method.setChecked(True)
                    self.set_payment_method("cash")
                    return
                account = " · ".join(filter(None, [
                    self.qr_config.get("bank_name"), self.qr_config.get("account_name"),
                ]))
                self.qr_account.setText(
                    f"สแกนชำระ {self.order.grand_total():,.2f} บาท\n{account or self.qr_config.get('name', 'PromptPay')}"
                )
                self.change.setText("รอตรวจสอบเงินเข้า")
                self.change.setStyleSheet("font-size:18px;font-weight:700;color:%s;" % PALETTE["warning_ink"])
            else:
                self.qr_payload = None
                self.transfer_confirmed.setChecked(False)
                self.refresh_change()

        def tendered(self) -> Decimal:
            if self.payment_method == "transfer":
                return self.order.grand_total()
            try:
                return money(self.amount.text() or "0")
            except (InvalidOperation, ValueError):
                return Decimal("0")

        def refresh_change(self):
            paid = self.tendered()
            if paid < self.order.grand_total():
                self.change.setText(f"ยังขาดอีก {self.order.grand_total() - paid:,.2f} บาท")
                self.change.setStyleSheet("font-size:18px;font-weight:700;color:%s;" % PALETTE["danger"])
            else:
                self.change.setText(f"เงินทอน {self.order.change_for(paid):,.2f} บาท")
                self.change.setStyleSheet("font-size:18px;font-weight:700;color:%s;" % PALETTE["success"])

        def confirm(self):
            if self.payment_method == "transfer" and not self.transfer_confirmed.isChecked():
                QMessageBox.warning(self, "ยังไม่ยืนยันเงินเข้า", "ตรวจรายการเงินเข้าก่อน แล้วทำเครื่องหมายยืนยัน")
                return
            if self.tendered() < self.order.grand_total():
                QMessageBox.warning(self, "ยอดชำระไม่พอ", "รับเงินมาน้อยกว่ายอดที่ต้องชำระ")
                return
            self.accept()

    class SettingsDialog(QDialog):
        """ตั้งค่าเฉพาะเครื่องนี้ — ไม่ถูกทับตอน sync แคตตาล็อกจาก ERP"""

        def __init__(self, parent, sample_sale_id: int | None):
            super().__init__(parent)
            self.sample_sale_id = sample_sale_id
            self.setWindowTitle("ตั้งค่า POS")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setMinimumSize(860, 560)
            self.resize(960, 620)

            outer = QVBoxLayout(self)
            outer.setContentsMargins(0, 0, 0, 0)
            outer.setSpacing(0)
            outer.addWidget(self.brand_bar())

            shell = QHBoxLayout()
            shell.setContentsMargins(0, 0, 0, 0)
            shell.setSpacing(0)
            outer.addLayout(shell, 1)

            self.pages = QVBoxLayout()
            self.pages.setContentsMargins(0, 12, 0, 12)
            self.pages.setSpacing(2)
            menu = QWidget()
            menu.setObjectName("card")
            menu.setLayout(self.pages)
            menu.setFixedWidth(255)
            shell.addWidget(menu)

            self.body = QVBoxLayout()
            self.body.setContentsMargins(20, 18, 20, 18)
            body_host = QWidget()
            body_host.setLayout(self.body)
            shell.addWidget(body_host, 1)
            footer = QHBoxLayout()
            footer.addStretch(1)
            close_button = QPushButton("× ปิดหน้าต่าง")
            close_button.setToolTip("ปิดหน้าต่างตั้งค่า")
            close_button.clicked.connect(self.reject)
            footer.addWidget(close_button)
            outer.addLayout(footer)

            self.sections = {
                "ข้อมูลเครื่อง POS": self.device_section,
                "เครื่องพิมพ์และใบเสร็จ": self.printer_section,
                "เครื่องชั่ง / Barcode": self.scale_section,
                "การ Sync และ API": self.sync_section,
                "สำรองและกู้คืน SQLite": self.backup_section,
                "ประวัติการพิมพ์ / Queue": self.queue_section,
            }
            heading = QLabel("ตั้งค่า POS")
            heading.setObjectName("cardHint")
            heading.setContentsMargins(16, 4, 12, 8)
            self.pages.addWidget(heading)

            group = QButtonGroup(self)
            for index, name in enumerate(self.sections):
                button = QPushButton(name)
                button.setObjectName("navItem")
                button.setCheckable(True)
                button.setChecked(index == 0)
                button.clicked.connect(lambda _, value=name: self.show_section(value))
                group.addButton(button)
                self.pages.addWidget(button)
            self.pages.addStretch(1)
            note = QLabel("การตั้งค่าอุปกรณ์เป็น local เฉพาะเครื่องนี้\nไม่ถูกทับด้วย sync ERP")
            note.setObjectName("muted")
            note.setContentsMargins(16, 0, 12, 8)
            note.setWordWrap(True)
            self.pages.addWidget(note)

            self.show_section("ข้อมูลเครื่อง POS")

        def brand_bar(self) -> QWidget:
            bar = QWidget()
            bar.setObjectName("brandBar")
            layout = QHBoxLayout(bar)
            layout.setContentsMargins(18, 0, 18, 0)
            mark = QLabel("★")
            mark.setObjectName("brandMark")
            name = QLabel("PopCentral POS")
            name.setObjectName("brandName")
            right = QLabel("HQ · POS-01 · ตั้งค่าเครื่องนี้")
            right.setObjectName("brandRight")
            close_button = QToolButton()
            close_button.setObjectName("dialogClose")
            close_button.setText("×")
            close_button.setToolTip("ปิดหน้าต่างตั้งค่า")
            close_button.setAutoRaise(True)
            close_button.clicked.connect(self.reject)
            layout.addWidget(mark)
            layout.addWidget(name)
            layout.addStretch(1)
            layout.addWidget(right)
            layout.addWidget(close_button)
            return bar

        def show_section(self, name: str) -> None:
            while self.body.count():
                item = self.body.takeAt(0)
                if item.widget():
                    item.widget().deleteLater()

            title = QLabel(name)
            title.setObjectName("cardTitle")
            self.body.addWidget(title)

            hint = QLabel(SECTION_HINTS.get(name, ""))
            hint.setObjectName("cardHint")
            self.body.addWidget(hint)

            card = QWidget()
            card.setObjectName("card")
            card_layout = QVBoxLayout(card)
            card_layout.setContentsMargins(20, 20, 20, 20)
            card_layout.addWidget(self.sections[name]())
            self.body.addWidget(card, 1)

        def device_section(self) -> QWidget:
            box = QWidget()
            form = QFormLayout(box)
            form.addRow("รหัสเครื่อง", QLabel("PY-TEST-01"))
            form.addRow("สาขา", QLabel("HQ"))
            form.addRow("ฐานข้อมูลในเครื่อง", QLabel("popstar-pos.db (WAL)"))
            return box

        def printer_section(self) -> QWidget:
            box = QWidget()
            outer = QHBoxLayout(box)

            left = QWidget()
            form = QFormLayout(left)
            self.profile_name = QLineEdit("เคาน์เตอร์หลัก HQ")
            self.driver = QComboBox()
            self.driver.addItems(["EPSON ESC/POS", "STAR TSP100", "Generic ESC/POS", "Mock printer"])
            self.connection = QComboBox()
            self.connection.addItems(["USB", "Serial (COM)", "Network (IP)"])
            self.address = QLineEdit("USB001")
            self.windows_printer = QComboBox()
            self.windows_printer.addItem("ยังไม่เลือกเครื่องพิมพ์ Windows", "")
            self._load_windows_printers()
            self.windows_printer.currentIndexChanged.connect(self._select_windows_printer)
            self.paper = QComboBox()
            self.paper.addItems(["80 mm", "58 mm"])
            self.paper.currentIndexChanged.connect(self.refresh_preview)
            self.drawer = QComboBox()
            self.drawer.addItems(["เปิดเมื่อรับเงินสด", "ไม่เปิด"])
            form.addRow("ชื่อโปรไฟล์", self.profile_name)
            form.addRow("รุ่น/Driver", self.driver)
            form.addRow("เครื่องพิมพ์ที่ติดตั้งใน Windows", self.windows_printer)
            form.addRow("การเชื่อมต่อ", self.connection)
            form.addRow("Port / Address", self.address)
            form.addRow("หน้ากระดาษ", self.paper)
            form.addRow("ลิ้นชักเงินสด", self.drawer)

            actions = QHBoxLayout()
            test = QPushButton("ทดสอบพิมพ์")
            test.clicked.connect(self.test_print)
            save = QPushButton("บันทึกโปรไฟล์เครื่องพิมพ์")
            save.setObjectName("primary")
            save.clicked.connect(self.save_printer)
            actions.addWidget(test)
            actions.addWidget(save)
            form.addRow(actions)
            outer.addWidget(left, 1)

            right = QWidget()
            preview_layout = QVBoxLayout(right)
            caption = QLabel("ตัวอย่างก่อนพิมพ์ · 80mm")
            caption.setObjectName("cardHint")
            preview_layout.addWidget(caption)

            background = QWidget()
            background.setObjectName("receiptBg")
            background_layout = QVBoxLayout(background)
            background_layout.setContentsMargins(22, 22, 22, 22)
            self.preview = QLabel()
            self.preview.setObjectName("receiptPaper")
            self.preview.setAlignment(Qt.AlignTop)
            background_layout.addWidget(self.preview)
            background_layout.addStretch(1)
            preview_layout.addWidget(background, 1)
            outer.addWidget(right, 1)

            self.refresh_preview()
            return box

        def refresh_preview(self) -> None:
            if self.sample_sale_id is None:
                self.preview.setText("ยังไม่มีบิลให้ดูตัวอย่าง — ขายหนึ่งบิลก่อน")
                return
            self.preview.setText(receipt_for(service.db, self.sample_sale_id))

        def _load_windows_printers(self) -> None:
            selected = SettingsService(service.db).get_device_setting("windows_printer_queue", "")
            for printer_name in installed_printer_names():
                self.windows_printer.addItem(printer_name, printer_name)
            index = self.windows_printer.findData(selected)
            if index >= 0:
                self.windows_printer.setCurrentIndex(index)

        def _select_windows_printer(self) -> None:
            printer_name = str(self.windows_printer.currentData() or "")
            if printer_name:
                self.address.setText(printer_name)

        def test_print(self) -> None:
            printer_name = str(self.windows_printer.currentData() or "")
            if not printer_name:
                QMessageBox.warning(self, "ยังไม่ได้เลือกเครื่องพิมพ์", "เลือก printer ที่ Windows ติดตั้งก่อนทดสอบพิมพ์")
                return
            text = receipt_for(service.db, self.sample_sale_id) if self.sample_sale_id else "PopCentral POS\nทดสอบเครื่องพิมพ์\n"
            try:
                print_text_to_windows_queue(
                    text, printer_name, paper_width_mm=active_paper_width(service.db)
                )
            except Exception as error:
                QMessageBox.critical(self, "ส่งงานพิมพ์ไม่สำเร็จ", str(error))
                return
            QMessageBox.information(self, "ทดสอบพิมพ์", f"ส่งใบเสร็จตัวอย่างไปที่ {printer_name} แล้ว")

        def save_printer(self) -> None:
            drivers = ["EPSON_ESC_POS", "STAR", "GENERIC_ESC_POS", "MOCK"]
            profile_name = self.profile_name.text().strip()
            if not profile_name:
                QMessageBox.warning(self, "บันทึกไม่ได้", "กรุณาระบุชื่อโปรไฟล์เครื่องพิมพ์")
                return
            try:
                SettingsService(service.db).save_printer_profile(PrinterProfile(
                    name=profile_name,
                    driver_type=drivers[self.driver.currentIndex()],
                    connection_type=["USB", "SERIAL", "NETWORK"][self.connection.currentIndex()],
                    address=self.address.text().strip() or None,
                    paper_width_mm=80 if self.paper.currentIndex() == 0 else 58,
                    open_drawer=self.drawer.currentIndex() == 0,
                ))
                SettingsService(service.db).set_device_setting(
                    "windows_printer_queue", str(self.windows_printer.currentData() or "")
                )
            except Exception as error:
                QMessageBox.critical(self, "บันทึกไม่ได้", str(error))
                return
            QMessageBox.information(self, "บันทึกแล้ว", f"บันทึกโปรไฟล์ {profile_name} แล้ว")

        def scale_section(self) -> QWidget:
            box = QWidget()
            layout = QVBoxLayout(box)
            profiles = load_scale_profiles(service.db)
            if not profiles:
                layout.addWidget(QLabel("ยังไม่ได้รับรูปแบบป้ายเครื่องชั่งจาก ERP — sync ก่อนขายสินค้าชั่ง"))
                return box
            table = QTableWidget(len(profiles), 5)
            table.setHorizontalHeaderLabels(["รหัส", "ขึ้นต้น", "PLU", "มูลค่า", "check digit"])
            for row, profile in enumerate(profiles):
                for column, value in enumerate([
                    profile["code"], profile["prefix"], profile["plu_length"],
                    f"{profile['value_length']} ({profile['value_type']})", profile["check_digit"],
                ]):
                    table.setItem(row, column, QTableWidgetItem(str(value)))
            table.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
            layout.addWidget(QLabel("รูปแบบป้ายมาจาก ERP เครื่องนี้ไม่เดารูปแบบเอง"))
            layout.addWidget(table)
            return box

        def sync_section(self) -> QWidget:
            box = QWidget()
            outer = QVBoxLayout(box)

            # สถานะการเชื่อม ERP — อ่านจาก worker/online context ที่ bootstrap สร้าง
            status = QFormLayout()
            if online is not None:
                state = "เชื่อม ERP ได้" if getattr(online.worker, "online", online.online) else "ออฟไลน์ (จะส่งเมื่อเน็ตกลับ)"
                status.addRow("สถานะ", QLabel(state))
                status.addRow("สาขา", QLabel(str(online.branch_id or "-")))
                status.addRow("เครื่อง", QLabel(str(online.terminal_id or "-")))
                pending = getattr(online.worker, "pending", None)
                status.addRow("บิลรอส่ง", QLabel(f"{pending if pending is not None else service.pending_sync_count()} ใบ"))
            else:
                status.addRow("สถานะ", QLabel("ยังไม่ผูกกับ ERP (โหมดออฟไลน์/ทดสอบ)"))
                status.addRow("บิลรอส่ง", QLabel(f"{service.pending_sync_count()} ใบ"))
            status.addRow("อัตรา VAT ที่ใช้อยู่", QLabel(f"{service.vat_rate():g}%"))
            outer.addLayout(status)

            # แสดงสถานะเป็นรายชุด เพื่อแยกให้ได้ว่าเน็ตกลับแล้วแต่ catalog หรือ
            # cashier ยังไม่ลงเครื่องหรือไม่ โดยไม่ต้องเปิดดูฐานข้อมูลเอง
            if online is not None:
                labels = {
                    "device_profile": "โปรไฟล์เครื่อง", "catalog": "สินค้า/ราคา", "cashiers": "ผู้ใช้งาน POS",
                    "sales_outbox": "คิวบิลขาย", "auth_events": "คิวประวัติการเข้าใช้",
                }
                sync_state = online.provisioning.sync_status()
                sync_box = QFormLayout()
                for entity, label in labels.items():
                    row = sync_state.get(entity)
                    if not row:
                        value = "ยังไม่เคย sync"
                    elif row["status"] == "synced":
                        value = f"สำเร็จ · {row['item_count']} รายการ · {row['last_success_at']}"
                    elif row["status"] == "failed":
                        value = f"ผิดพลาด · {row['last_error'] or 'ตรวจสอบ log'}"
                    else:
                        value = str(row["status"])
                    sync_box.addRow(label, QLabel(value))
                outer.addLayout(sync_box)

            actions = QHBoxLayout()
            test_sync = QPushButton("ทดสอบการเชื่อมต่อและ sync แคชเชียร์")
            test_sync.setObjectName("primary")
            test_sync.clicked.connect(self.test_connection_and_sync)
            show_logs = QPushButton("ดูบันทึกการเชื่อมต่อ")
            show_logs.clicked.connect(self.show_sync_logs)
            actions.addWidget(test_sync)
            actions.addWidget(show_logs)
            outer.addLayout(actions)

            # ผูกเครื่องกับ ERP — บันทึก server URL + device token ลง pos-config.json
            if data_dir is not None:
                outer.addWidget(QLabel("\nผูกเครื่องกับ ERP (บันทึกแล้วเปิดโปรแกรมใหม่เพื่อเชื่อม)"))
                help_text = QLabel(
                    "ใช้ URL และ Device token จาก ERP > ตั้งค่าระบบ > เพิ่มเครื่อง POS\n"
                    "ถ้า ERP ยังเป็น http:// ให้กรอก http:// ตามจริง โปรแกรมจะบันทึก allow_insecure ให้เฉพาะเครื่องนี้"
                )
                help_text.setWordWrap(True)
                outer.addWidget(help_text)
                pair = QFormLayout()
                current = load_device_config(data_dir)
                self.server_url = QLineEdit(current.server_url if current else "")
                self.server_url.setPlaceholderText("เช่น http://27.254.143.219 หรือ https://erp.example.com")
                self.device_token = QLineEdit(current.device_token if current else "")
                self.device_token.setPlaceholderText("วาง device token ที่ออกจาก ERP")
                self.device_token.setEchoMode(QLineEdit.Password)
                pair.addRow("ที่อยู่ ERP", self.server_url)
                pair.addRow("Device token", self.device_token)
                save = QPushButton("บันทึกการผูกเครื่อง")
                save.setObjectName("primary")
                save.clicked.connect(self.save_pairing)
                pair.addRow(save)
                outer.addLayout(pair)

            return box

        def test_connection_and_sync(self) -> None:
            if online is None:
                QMessageBox.warning(self, "ยังไม่ผูก ERP", "บันทึก URL และ Device token ก่อนทดสอบการเชื่อมต่อ")
                return
            try:
                profile = online.provisioning.ping()
                branch_id = int(profile.get("branch_id") or online.branch_id or 0)
                result = online.provisioning.pull_cashiers(branch_id)
                online.online = True
                online.worker.wake()
                QMessageBox.information(self, "เชื่อมต่อสำเร็จ", f"sync แคชเชียร์ {result['upserted']} คนแล้ว")
            except Exception as error:
                online.online = False
                QMessageBox.warning(self, "เชื่อมต่อไม่สำเร็จ", str(error))

        def show_sync_logs(self) -> None:
            rows = service.db.execute(
                "SELECT direction, status, message, created_at FROM sync_logs ORDER BY id DESC LIMIT 20"
            ).fetchall()
            auth_pending = service.db.execute("SELECT count(*) FROM auth_events_outbox WHERE synced = 0").fetchone()[0]
            text = "\n".join(f"{row['created_at']} · {row['direction']} · {row['status']} · {row['message'] or '-'}" for row in rows)
            QMessageBox.information(self, "บันทึกการเชื่อมต่อ", f"Audit login รอส่ง: {auth_pending}\n\n{text or 'ยังไม่มีบันทึก'}")

        def save_pairing(self) -> None:
            url = self.server_url.text().strip()
            token = self.device_token.text().strip()
            if not url or not token:
                QMessageBox.warning(self, "ข้อมูลไม่ครบ", "กรอกที่อยู่ ERP และ device token ให้ครบ")
                return
            insecure = url.startswith("http://")  # ยอม http เฉพาะที่ผู้ใช้ตั้งใจ (แลบ/ในเครื่อง)
            try:
                save_device_config(data_dir, DeviceConfig(server_url=url, device_token=token, allow_insecure=insecure))
            except Exception as error:
                QMessageBox.critical(self, "บันทึกไม่สำเร็จ", str(error))
                return
            QMessageBox.information(self, "ผูกเครื่องแล้ว", "บันทึกการเชื่อมต่อแล้ว\nปิดและเปิด POS ใหม่เพื่อเชื่อมกับ ERP และ sync ข้อมูล")
            self.accept()

        def backup_section(self) -> QWidget:
            box = QWidget()
            layout = QVBoxLayout(box)
            layout.addWidget(QLabel("สำรอง SQLite เป็นไฟล์เดียวที่กู้คืนได้จริง โดยไม่แตะยอดขายหรือคิวที่ค้าง"))
            backup = QPushButton("สำรองฐานข้อมูลเครื่องนี้")
            backup.setObjectName("primary")
            backup.clicked.connect(self.backup_database)
            layout.addWidget(backup)
            return box

        def backup_database(self) -> None:
            if data_dir is None:
                QMessageBox.warning(self, "สำรองไม่ได้", "ไม่พบโฟลเดอร์ข้อมูล POS")
                return
            backup_dir = data_dir / "backups"
            backup_dir.mkdir(parents=True, exist_ok=True)
            path = backup_dir / f"pos-{datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')}.sqlite"
            try:
                target = sqlite3.connect(path)
                service.db.backup(target)
                target.close()
            except Exception as error:
                QMessageBox.warning(self, "สำรองไม่สำเร็จ", str(error))
                return
            QMessageBox.information(self, "สำรองแล้ว", f"บันทึกฐานข้อมูลไว้ที่\n{path}")

        def queue_section(self) -> QWidget:
            box = QWidget()
            layout = QVBoxLayout(box)
            jobs = service.db.execute(
                """SELECT s.document_no, p.status, p.attempts FROM print_jobs p
                JOIN sales s ON s.id = p.sale_id ORDER BY p.id DESC LIMIT 30"""
            ).fetchall()
            if not jobs:
                layout.addWidget(QLabel("ยังไม่มีงานพิมพ์"))
                return box
            table = QTableWidget(len(jobs), 3)
            table.setHorizontalHeaderLabels(["เลขที่บิล", "สถานะ", "ครั้งที่พยายาม"])
            for row, job in enumerate(jobs):
                for column, value in enumerate([job["document_no"], job["status"], job["attempts"]]):
                    table.setItem(row, column, QTableWidgetItem(str(value)))
            table.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
            layout.addWidget(table)
            return box

    class AdminAuthDialog(QDialog):
        def __init__(self, parent):
            super().__init__(parent)
            self.setWindowTitle("ยืนยันผู้ดูแลก่อนตั้งค่า POS")
            self.setMinimumWidth(390)
            layout = QVBoxLayout(self)
            hint = QLabel("เมนูตั้งค่าใช้ได้เฉพาะผู้ดูแลที่มีสิทธิ์ตั้งค่า POS")
            hint.setWordWrap(True)
            layout.addWidget(hint)
            form = QFormLayout()
            self.username = QLineEdit()
            self.username.setPlaceholderText("username / email / เบอร์โทร")
            self.password = QLineEdit()
            self.password.setEchoMode(QLineEdit.Password)
            self.password.setPlaceholderText("รหัสผ่าน ERP")
            form.addRow("ผู้ดูแล", self.username)
            form.addRow("รหัสผ่าน", self.password)
            layout.addLayout(form)
            buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
            buttons.button(QDialogButtonBox.Ok).setText("ยืนยัน")
            buttons.accepted.connect(self.authorize)
            buttons.rejected.connect(self.reject)
            self.password.returnPressed.connect(self.authorize)
            layout.addWidget(buttons)

        def authorize(self):
            try:
                online.provisioning.authorize_admin(
                    self.username.text().strip(), self.password.text()
                )
            except Exception as error:
                QMessageBox.warning(self, "ยืนยันไม่สำเร็จ", str(error))
                return
            self.accept()

    class PosWindow(QMainWindow):
        def __init__(self, cashier=None):
            super().__init__()
            self.cashier = cashier
            self.order = Order()
            self.category = ALL_CATEGORIES
            self.shift_id: int | None = None
            self.opening_cash: Decimal | None = None
            self.last_sale_id: int | None = None
            self._product_columns: int | None = None
            layout_version = layout_config.get("version", 1)
            self.setWindowTitle(f"PopCentral POS — พร้อมใช้งาน · Layout {layout_version}")
            self.setStyleSheet(STYLE)
            self.settings_shortcut = QShortcut(QKeySequence("Ctrl+Alt+S"), self)
            self.settings_shortcut.activated.connect(self.open_settings)

            root = QWidget()
            columns = QHBoxLayout(root)
            columns.setContentsMargins(0, 0, 0, 0)
            columns.setSpacing(0)
            order_panel = self.build_order_panel()
            product_panel = self.build_product_panel()
            # The web designer controls pane order, while the splitter keeps both
            # panes usable on 14-15 inch POS screens instead of squeezing buttons.
            order_x = self._layout_x("cart", 8)
            product_x = self._layout_x("product_grid", 1)
            splitter = QSplitter(Qt.Horizontal)
            splitter.setObjectName("primarySplitter")
            splitter.setChildrenCollapsible(False)
            splitter.setHandleWidth(6)
            if order_x < product_x:
                first, second = order_panel, product_panel
                first.setMinimumWidth(400)
                second.setMinimumWidth(520)
            else:
                first, second = product_panel, order_panel
                first.setMinimumWidth(520)
                second.setMinimumWidth(400)
            splitter.addWidget(first)
            splitter.addWidget(second)
            splitter.setStretchFactor(0, 6 if first is product_panel else 4)
            splitter.setStretchFactor(1, 4 if second is order_panel else 6)
            splitter.setSizes([740, 520] if first is product_panel else [520, 740])
            self.primary_splitter = splitter
            columns.addWidget(splitter)
            self.setCentralWidget(root)

            self.refresh_products()
            self.refresh_order()
            # ราคาที่มีผลตามเวลาอาจเปลี่ยนระหว่างเปิดโปรแกรม จึงวาดปุ่มสินค้า
            # ใหม่เป็นระยะโดยใช้ clock ที่ sync จาก ERP ไม่ต้อง build รุ่นใหม่
            self.price_timer = QTimer(self)
            self.price_timer.timeout.connect(self.refresh_products)
            self.price_timer.start(30000)

        def resizeEvent(self, event) -> None:
            """Keep the working area usable on common 14-15 inch POS screens."""
            super().resizeEvent(event)
            compact = self.width() <= 1450 or self.height() <= 900
            mode_changed = self.property("compact") != compact
            if mode_changed:
                self.setProperty("compact", compact)
                self.style().unpolish(self)
                self.style().polish(self)

            columns = self._product_columns_for_width()
            if mode_changed or columns != self._product_columns:
                self.refresh_products()

        # ---------- ซ้าย: บิล ----------

        def build_order_panel(self) -> QWidget:
            panel = QWidget()
            panel.setObjectName("orderPanel")
            layout = QVBoxLayout(panel)
            layout.setContentsMargins(0, 0, 0, 0)
            layout.setSpacing(0)

            head = QWidget()
            head.setObjectName("orderHead")
            head_layout = QVBoxLayout(head)
            head_layout.setContentsMargins(12, 8, 12, 8)
            head_layout.setSpacing(6)
            self.cashier_label = QLabel("บิลปัจจุบัน · ยังไม่ได้เริ่มขาย")
            self.cashier_label.setWordWrap(True)
            head_layout.addWidget(self.cashier_label)
            action_row = QHBoxLayout()
            action_row.setSpacing(6)
            self.auth_button = QPushButton("เปิดกะ")
            self.auth_button.setObjectName("headerAction")
            self.auth_button.setToolTip("ยืนยันผู้ขายและใส่เงินทอนตั้งต้นก่อนเริ่มขาย")
            self.auth_button.clicked.connect(self.ensure_sale_session)
            action_row.addWidget(self.auth_button, 1)
            report = QPushButton("ยอดวันนี้")
            report.setObjectName("headerAction")
            report.setToolTip("ดูยอดขายของเครื่องนี้จาก SQLite")
            report.clicked.connect(self.show_daily_sales)
            action_row.addWidget(report, 1)
            cash = QPushButton("เงินสด")
            cash.setObjectName("headerAction")
            cash.setToolTip("บันทึกเงินเข้า นำส่ง หรือเบิกจ่ายจากลิ้นชัก")
            cash.clicked.connect(self.record_cash_movement)
            action_row.addWidget(cash, 1)
            close_shift = QPushButton("ปิดกะ")
            close_shift.setObjectName("headerAction")
            close_shift.setToolTip("นับเงินและปิดกะขาย")
            close_shift.clicked.connect(self.close_current_shift)
            action_row.addWidget(close_shift, 1)
            settings = QPushButton("⚙")
            settings.setObjectName("headerAction")
            settings.setToolTip("ตั้งค่าเครื่อง POS สำหรับ IT")
            settings.clicked.connect(self.open_settings)
            settings.setMinimumWidth(42)
            action_row.addWidget(settings)
            head_layout.addLayout(action_row)
            layout.addWidget(head)

            self.table = QTableWidget(0, 4)
            self.table.setHorizontalHeaderLabels(["สินค้า", "จำนวน", "ราคา", "รวม"])
            self.table.verticalHeader().setVisible(False)
            self.table.setSelectionBehavior(QTableWidget.SelectRows)
            self.table.setEditTriggers(QTableWidget.NoEditTriggers)
            self.table.horizontalHeader().setSectionResizeMode(0, QHeaderView.Stretch)
            for column in (1, 2, 3):
                self.table.horizontalHeader().setSectionResizeMode(column, QHeaderView.ResizeToContents)
            self.table.itemSelectionChanged.connect(self.on_line_selected)
            layout.addWidget(self.table, 1)

            totals = QWidget()
            totals.setObjectName("totalBox")
            totals_layout = QVBoxLayout(totals)
            self.subtotal_label = QLabel()
            self.subtotal_label.setObjectName("totalLabel")
            self.vat_label = QLabel()
            self.vat_label.setObjectName("vatLine")
            self.grand_label = QLabel()
            self.grand_label.setObjectName("grandTotal")
            self.grand_label.setAlignment(Qt.AlignRight)
            totals_layout.addWidget(self.subtotal_label)
            totals_layout.addWidget(self.vat_label)
            totals_layout.addWidget(self.grand_label)
            layout.addWidget(totals)

            layout.addWidget(self.build_numpad())

            self.status = QLabel()
            self.status.setObjectName("statusBar")
            layout.addWidget(self.status)
            return panel

        def _layout_x(self, component_type: str, fallback: int) -> int:
            return next((int(item.get("x", fallback)) for item in layout_config.get("components", [])
                         if item.get("type") == component_type), fallback)

        def build_numpad(self) -> QWidget:
            box = QWidget()
            grid = QGridLayout(box)

            self.mode_group = QButtonGroup(box)
            for column, (mode, label) in enumerate([(QTY, "จำนวน"), (PRICE, "ราคา"), (DISCOUNT, "ส่วนลด")]):
                button = QPushButton(label)
                button.setMinimumHeight(34)
                button.setCheckable(True)
                button.setChecked(mode == QTY)
                button.clicked.connect(lambda _, value=mode: self.set_mode(value))
                self.mode_group.addButton(button)
                grid.addWidget(button, 0, column)

            for row, keys in enumerate(NUMPAD_KEYS, start=1):
                for column, key in enumerate(keys):
                    button = QPushButton("⌫" if key == "backspace" else key)
                    button.setMinimumHeight(34)
                    button.clicked.connect(lambda _, value=key: self.press(value))
                    grid.addWidget(button, row, column)

            sign = QPushButton("+/−")
            sign.setMinimumHeight(34)
            sign.clicked.connect(lambda: self.press("+/-"))
            grid.addWidget(sign, 5, 0)

            remove = QPushButton("ลบรายการ")
            remove.setMinimumHeight(34)
            remove.setObjectName("voidBtn")
            remove.clicked.connect(self.remove_line)
            grid.addWidget(remove, 5, 1)

            receipt = QPushButton("ดูใบเสร็จล่าสุด")
            receipt.setMinimumHeight(34)
            receipt.clicked.connect(self.show_last_receipt)
            grid.addWidget(receipt, 5, 2)

            clear = QPushButton("ล้างบิล")
            clear.setMinimumHeight(40)
            clear.clicked.connect(self.clear_order)
            grid.addWidget(clear, 6, 0)

            pay = QPushButton("รับชำระเงิน")
            pay.setMinimumHeight(40)
            pay.setObjectName("payBtn")
            pay.clicked.connect(self.pay)
            grid.addWidget(pay, 6, 1, 1, 2)
            return box

        # ---------- ขวา: สินค้า ----------

        def build_product_panel(self) -> QWidget:
            panel = QWidget()
            layout = QVBoxLayout(panel)
            layout.setContentsMargins(14, 12, 14, 12)
            layout.setSpacing(10)

            self.scan = QLineEdit()
            self.scan.setPlaceholderText("สแกนบาร์โค้ด ป้ายเครื่องชั่ง หรือพิมพ์ค้นหาสินค้า")
            self.scan.returnPressed.connect(self.on_scan)
            self.scan.textChanged.connect(self.refresh_products)
            layout.addWidget(self.scan)

            self.category_bar = QHBoxLayout()
            self.category_bar.setSpacing(8)
            layout.addLayout(self.category_bar)

            self.grid_host = QWidget()
            self.grid = QGridLayout(self.grid_host)
            self.grid.setContentsMargins(0, 0, 0, 0)
            self.grid.setHorizontalSpacing(10)
            self.grid.setVerticalSpacing(10)
            scroll = QScrollArea()
            scroll.setObjectName("productScroll")
            scroll.setWidgetResizable(True)
            scroll.setWidget(self.grid_host)
            layout.addWidget(scroll, 1)

            self.build_category_bar()
            return panel

        def _product_columns_for_width(self) -> int:
            """Choose a grid that fits without a horizontal scrollbar."""
            available = self.grid_host.width()
            if available <= 0:
                return 4
            gap = 10
            minimum_tile = 128 if self.property("compact") else 154
            return max(1, min(4, (available + gap) // (minimum_tile + gap)))

        def build_category_bar(self) -> None:
            group = QButtonGroup(self)
            for name in categories(service.db):
                button = QPushButton(name)
                button.setCheckable(True)
                button.setChecked(name == ALL_CATEGORIES)
                button.clicked.connect(lambda _, value=name: self.set_category(value))
                group.addButton(button)
                self.category_bar.addWidget(button)
            self.category_bar.addStretch(1)

        def set_category(self, name: str) -> None:
            self.category = name
            self.refresh_products()

        def refresh_products(self) -> None:
            while self.grid.count():
                self.grid.takeAt(0).widget().deleteLater()

            columns = self._product_columns_for_width()
            self._product_columns = columns
            available = max(self.grid_host.width(), columns * 110 + (columns - 1) * 10)
            tile_width = max(110, (available - (columns - 1) * 10) // columns)
            tile_height = 104 if self.property("compact") else 118
            term = self.scan.text().strip()
            # ตัวเลขล้วนคือกำลังยิงบาร์โค้ด ไม่ใช่ค้นหา — อย่าให้ตารางกระพริบระหว่างสแกน
            search = "" if term.isdigit() else term
            for index, product in enumerate(product_grid(service.db, category=self.category, search=search)):
                price, _ = service.effective_price(int(product["id"]), product["price"] or 0)
                sku = str(product["sku"] or "").strip()
                unit = str(product["unit_name"] or "หน่วย")
                tile = QToolButton()
                tile.setObjectName("tile")
                tile.setToolButtonStyle(Qt.ToolButtonTextOnly)
                tile.setText(f"{product['name']}\n{price:,.2f} ฿ / {unit}" + (f"\n{sku}" if sku else ""))
                tile.setToolTip(str(product["name"] or ""))
                tile.setMinimumSize(QSize(tile_width, tile_height))
                tile.setMaximumHeight(tile_height)
                tile.clicked.connect(lambda _, row=product: self.add_product_row(row))
                self.grid.addWidget(tile, index // columns, index % columns)
            for column in range(PRODUCT_TILE_COLUMNS):
                self.grid.setColumnStretch(column, 1 if column < columns else 0)

        # ---------- การกระทำ ----------

        def add_product_row(self, product) -> None:
            price, price_version = service.effective_price(int(product["id"]), product["price"] or 0)
            if price <= 0:
                QMessageBox.warning(self, "ยังไม่ได้ตั้งราคา", f"{product['name']} ยังไม่มีราคาขาย")
                return
            self.order.add_product(OrderLine(
                product_id=int(product["id"]), name=product["name"], unit_name=product["unit_name"],
                qty=Decimal("1"), unit_price=price, is_vat=bool(product["is_vat"]), price_version=price_version,
            ))
            self.refresh_order()

        def on_scan(self) -> None:
            scanned = self.scan.text().strip()
            if not scanned:
                return

            # หาบาร์โค้ดที่ลงทะเบียนไว้ก่อนเสมอ แล้วค่อยตีความเป็นป้ายชั่ง
            # กันสินค้านำเข้าที่ขึ้นต้น 800 ถูกอ่านเป็นป้ายชั่งแล้วคิดเงินผิด
            product = service.lookup_barcode(scanned)
            if product:
                self.order.add_product(OrderLine(
                    product_id=int(product["id"]), name=product["name"], unit_name=product["unit_name"],
                    qty=Decimal("1"), unit_price=service.effective_price(int(product["id"]), product["price"] or 0)[0],
                    is_vat=bool(product["is_vat"]), barcode=scanned, source_barcode=scanned,
                    barcode_type=product["barcode_type"],
                ))
                self.finish_scan()
                return

            if decode_scale_label(service.db, scanned) is not None:
                try:
                    cart_line = scale_cart_line(service.db, scanned)
                except ValueError as error:
                    QMessageBox.warning(self, "ป้ายเครื่องชั่ง", str(error))
                    return
                detail = service.db.execute(
                    "SELECT name, unit_name, is_vat FROM products WHERE id = ?", (cart_line.product_id,)
                ).fetchone()
                self.order.add_product(OrderLine(
                    product_id=cart_line.product_id, name=detail["name"], unit_name=detail["unit_name"],
                    qty=cart_line.qty, unit_price=cart_line.unit_price, is_vat=bool(detail["is_vat"]),
                    barcode=cart_line.barcode, source_barcode=cart_line.source_barcode,
                    barcode_type="SCALE_WEIGHT", price_version=cart_line.price_version, locked_qty=True,
                ))
                self.finish_scan()
                return

            QMessageBox.warning(self, "ไม่พบสินค้า", f"ไม่พบบาร์โค้ด {scanned}")

        def finish_scan(self) -> None:
            self.scan.clear()
            self.scan.setFocus()
            self.refresh_order()

        def set_mode(self, mode: str) -> None:
            self.order.set_mode(mode)

        def press(self, key: str) -> None:
            self.order.press(key)
            self.refresh_order(keep_selection=True)

        def on_line_selected(self) -> None:
            rows = self.table.selectionModel().selectedRows()
            self.order.select(rows[0].row() if rows else None)

        def remove_line(self) -> None:
            self.order.remove_selected()
            self.refresh_order()

        def clear_order(self) -> None:
            if self.order.lines and QMessageBox.question(
                self, "ล้างบิล", "ล้างรายการทั้งบิลใช่หรือไม่"
            ) != QMessageBox.Yes:
                return
            self.order.clear()
            self.refresh_order()

        def pay(self) -> None:
            if not self.order.lines:
                return
            if not self.ensure_sale_session():
                return
            dialog = PaymentDialog(self, self.order)
            if dialog.exec() != QDialog.Accepted:
                return

            try:
                document_no = service.next_document_no(terminal_id)
                sale_id = service.checkout(
                    document_no=document_no,
                    branch_id=branch_id, terminal_id=terminal_id, shift_id=self.shift_id,
                    cashier_id=int(self.cashier["id"]), lines=self.order.to_cart_lines(),
                    payment_method=dialog.payment_method, paid_amount=dialog.tendered(),
                    payment_reference=str(dialog.qr_config.get("code") or "") or None,
                    qr_payload=dialog.qr_payload,
                    payment_confirmed=dialog.payment_method == "transfer" and dialog.transfer_confirmed.isChecked(),
                )
                if online is not None:
                    online.worker.wake()  # ส่งบิลขึ้น ERP ทันที ไม่รอรอบถัดไป
            except Exception as error:
                QMessageBox.critical(self, "บันทึกบิลไม่สำเร็จ", str(error))
                return

            self.last_sale_id = sale_id
            printer_name = str(SettingsService(service.db).get_device_setting("windows_printer_queue", "") or "")
            if printer_name:
                try:
                    print_text_to_windows_queue(
                        receipt_for(service.db, sale_id), printer_name,
                        paper_width_mm=active_paper_width(service.db),
                    )
                    service.db.execute(
                        "UPDATE print_jobs SET status = 'printed', attempts = attempts + 1, last_error = NULL WHERE sale_id = ?",
                        (sale_id,),
                    )
                    service.db.commit()
                except Exception as error:
                    # Sale is already committed locally. Keep it sellable and leave a
                    # diagnosable queue record instead of rolling back a paid bill.
                    service.db.execute(
                        "UPDATE print_jobs SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE sale_id = ?",
                        (str(error)[:500], sale_id),
                    )
                    service.db.commit()
            detail = (f"เงินทอน {self.order.change_for(dialog.tendered()):,.2f} บาท"
                      if dialog.payment_method == "cash" else "รับชำระผ่านโอน / QR แล้ว")
            QMessageBox.information(self, "รับชำระแล้ว", f"บิล {document_no}\n{detail}")
            self.order.clear()
            self.refresh_order()

        def ensure_sale_session(self) -> bool:
            """Authenticate and open a shift only when the operator starts selling."""
            if self.cashier is not None and self.shift_id is not None:
                return True

            try:
                login = LoginDialog()
                # Some Windows POS setups keep the on-screen keyboard above the
                # parent window. Show and raise the login prompt before exec().
                login.show()
                login.raise_()
                login.activateWindow()
                app.processEvents()
                login_result = login.exec()
            except Exception as error:
                QMessageBox.critical(
                    self,
                    "เปิดหน้าล็อกอินไม่ได้",
                    f"POS เปิดหน้าต่างเริ่มขายไม่สำเร็จ: {error}\n\n"
                    "กรุณาถ่ายภาพข้อความนี้ส่งให้ฝ่าย IT",
                )
                return False
            if login_result != QDialog.Accepted or login.cashier is None:
                return False

            existing_shift = service.db.execute(
                "SELECT id, cashier_id, opening_cash FROM shifts WHERE terminal_id = ? AND status = 'open'",
                (terminal_id,),
            ).fetchone()
            if existing_shift and int(existing_shift["cashier_id"]) != int(login.cashier["id"]):
                QMessageBox.warning(self, "เริ่มขายไม่ได้", "เครื่องนี้มีกะของแคชเชียร์คนอื่นเปิดอยู่ ต้องปิดกะหรือส่งมอบกะก่อน")
                return False

            if existing_shift:
                existing_dialog = ExistingShiftDialog(self, existing_shift)
                existing_dialog.show()
                existing_dialog.raise_()
                existing_dialog.activateWindow()
                app.processEvents()
                if existing_dialog.exec() != QDialog.Accepted:
                    return False
                opening_cash = money(existing_shift["opening_cash"])
                if existing_dialog.close_existing:
                    # Attach the authenticated operator to the existing shift so the
                    # normal audited close flow can reconcile it before reopening.
                    self.cashier = login.cashier
                    self.shift_id = int(existing_shift["id"])
                    self.opening_cash = opening_cash
                    self.close_current_shift()
                    if self.shift_id is not None:
                        self.cashier = None
                        self.shift_id = None
                        self.opening_cash = None
                        return False
                    existing_shift = None

            if not existing_shift:
                # Use our own numeric dialog: Qt's static getDouble dialog can be
                # covered by the Windows On-Screen Keyboard on touch POS devices.
                opening_dialog = OpeningShiftDialog(self)
                opening_dialog.show()
                opening_dialog.raise_()
                opening_dialog.activateWindow()
                app.processEvents()
                if opening_dialog.exec() != QDialog.Accepted:
                    return False
                opening_cash = opening_dialog.opening_cash

            try:
                shift_id = service.open_shift(
                    branch_id, terminal_id, int(login.cashier["id"]), opening_cash
                )
            except Exception as error:
                QMessageBox.warning(self, "เริ่มขายไม่ได้", str(error))
                return False

            self.cashier = login.cashier
            self.shift_id = shift_id
            self.opening_cash = opening_cash
            if online is not None:
                try:
                    # Never wait for ERP from the GUI thread. The sync worker opens the
                    # server shift first, then uploads any sale that belongs to it.
                    service.queue_shift_open(self.shift_id)
                    online.worker.wake()
                except Exception as error:
                    # The local shift is already committed and remains usable. Keep the
                    # reason visible in the normal sync queue instead of blocking sales.
                    service.record_auth_event(
                        str(self.cashier["code"] or ""),
                        "shift_open_queue",
                        False,
                        str(error)[:500],
                    )

            self.cashier_label.setText(
                f"บิลปัจจุบัน · {self.cashier['name']} · เงินทอนต้นกะ {opening_cash:,.2f} บาท"
            )
            self.auth_button.setText(f"กำลังขาย: {self.cashier['code']}")
            self.auth_button.setEnabled(False)
            layout_version = layout_config.get("version", 1)
            self.setWindowTitle(f"PopCentral POS — {self.cashier['name']} · Layout {layout_version}")
            self.refresh_order(keep_selection=True)
            return True

        def record_cash_movement(self) -> None:
            if self.shift_id is None:
                QMessageBox.information(self, "ยังไม่มีกะขาย", "เริ่มขายและเปิดกะก่อนบันทึกรายการเงินสด")
                return
            from PySide6.QtWidgets import QInputDialog
            labels = {"เงินเข้าลิ้นชัก": "cash_in", "นำส่งเงิน": "drop", "เบิกจ่าย": "payout"}
            label, ok = QInputDialog.getItem(self, "รายการเงินสด", "ประเภท", list(labels), 0, False)
            if not ok:
                return
            value, ok = QInputDialog.getDouble(self, "รายการเงินสด", "จำนวนเงิน (บาท)", 0.00, 0.01, 999999999.99, 2)
            if not ok:
                return
            reason, ok = QInputDialog.getText(self, "รายการเงินสด", "เหตุผล")
            if not ok or not reason.strip():
                return
            try:
                movement_uuid = service.record_cash_movement(
                    shift_id=self.shift_id, movement_type=labels[label], amount=money(str(value)), reason=reason.strip()
                )
            except Exception as error:
                QMessageBox.warning(self, "บันทึกไม่สำเร็จ", str(error))
                return
            local = service.db.execute(
                "SELECT server_id FROM shifts WHERE id = ?", (self.shift_id,)
            ).fetchone()
            if online is not None and online.online and local and local["server_id"]:
                try:
                    online.provisioning.record_server_cash_movement(
                        server_shift_id=int(local["server_id"]), movement_type=labels[label],
                        amount=value, reason=reason.strip(), movement_uuid=movement_uuid,
                    )
                    service.db.execute(
                        "UPDATE cash_movements SET sync_status = 'synced' WHERE movement_uuid = ?", (movement_uuid,)
                    )
                    service.db.execute(
                        "UPDATE sync_outbox SET status = 'synced', synced_at = ?, last_error = NULL WHERE aggregate_uuid = ?",
                        (service._now(), f"cash:{movement_uuid}"),
                    )
                    service.db.commit()
                except Exception as error:
                    QMessageBox.warning(self, "บันทึกในเครื่องแล้ว", f"ERP ยังไม่รับรายการนี้: {error}\nระบบจะลองส่งให้อัตโนมัติ")
            summary = service.shift_cash_summary(self.shift_id)
            self.status.setText(f"ยอดในลิ้นชักที่ควรมี {summary.expected_cash:,.2f} บาท")

        def close_current_shift(self) -> None:
            if self.shift_id is None:
                QMessageBox.information(self, "ยังไม่มีกะขาย", "ยังไม่มีกะที่เปิดอยู่")
                return
            from PySide6.QtWidgets import QInputDialog
            summary = service.shift_cash_summary(self.shift_id)
            counted, ok = QInputDialog.getDouble(
                self, "ปิดกะขาย", f"เงินสดนับจริง (ควรมี {summary.expected_cash:,.2f} บาท)",
                float(summary.expected_cash), 0.00, 999999999.99, 2,
            )
            if not ok:
                return
            note, ok = QInputDialog.getText(self, "ปิดกะขาย", "หมายเหตุ (ถ้ามี)")
            if not ok:
                return
            local_shift = service.db.execute("SELECT uuid, server_id FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()
            try:
                closed = service.close_shift(shift_id=self.shift_id, counted_cash=money(str(counted)), closing_note=note.strip())
            except Exception as error:
                QMessageBox.warning(self, "ปิดกะไม่ได้", str(error))
                return
            if online is not None and online.online and local_shift and local_shift["server_id"]:
                aggregate_uuid = f"shift:{local_shift['uuid']}:close"
                try:
                    online.provisioning.close_server_shift(
                        server_shift_id=int(local_shift["server_id"]), counted_cash=closed.counted_cash,
                        closing_note=note.strip() or None, idempotency_key=aggregate_uuid,
                    )
                    service.db.execute(
                        "UPDATE sync_outbox SET status = 'synced', synced_at = ?, last_error = NULL WHERE aggregate_uuid = ?",
                        (service._now(), aggregate_uuid),
                    )
                    service.db.commit()
                except Exception as error:
                    QMessageBox.warning(self, "ปิดกะในเครื่องแล้ว", f"ERP ยังไม่รับการปิดกะ: {error}\nระบบจะลองส่งให้อัตโนมัติ")
            difference = closed.cash_difference or Decimal("0")
            QMessageBox.information(self, "ปิดกะแล้ว", f"ยอดที่ควรมี {closed.expected_cash:,.2f} บาท\nส่วนต่าง {difference:,.2f} บาท")
            self.cashier = None
            self.shift_id = None
            self.opening_cash = None
            self.auth_button.setText("เปิดกะ")
            self.auth_button.setEnabled(True)
            self.cashier_label.setText("บิลปัจจุบัน · ยังไม่ได้เริ่มขาย")
            self.setWindowTitle(f"PopCentral POS — พร้อมใช้งาน · Layout {layout_config.get('version', 1)}")

        def open_settings(self) -> None:
            if service.has_local_it_pin():
                from PySide6.QtWidgets import QInputDialog
                pin, ok = QInputDialog.getText(
                    self, "ตั้งค่าเครื่อง POS", "กรอก Local IT PIN", QLineEdit.Password
                )
                if not ok:
                    return
                if not service.verify_local_it_pin(pin):
                    QMessageBox.warning(self, "ยืนยันไม่สำเร็จ", "Local IT PIN ไม่ถูกต้อง")
                    return
                SettingsDialog(self, self.last_sale_id).exec()
                return
            if online is None or not online.online:
                QMessageBox.warning(
                    self, "ต้องตั้ง Local IT PIN",
                    "เชื่อม ERP และยืนยันผู้ดูแลหนึ่งครั้งเพื่อตั้ง Local IT PIN สำหรับเครื่องนี้",
                )
                return
            if AdminAuthDialog(self).exec() == QDialog.Accepted:
                from PySide6.QtWidgets import QInputDialog
                pin, ok = QInputDialog.getText(
                    self, "ตั้ง Local IT PIN ครั้งแรก",
                    "PIN สำหรับเปิดตั้งค่าเครื่องนี้ (6-20 หลัก)", QLineEdit.Password,
                )
                if not ok:
                    return
                try:
                    service.set_local_it_pin(pin)
                except ValueError as error:
                    QMessageBox.warning(self, "PIN ไม่ถูกต้อง", str(error))
                    return
                SettingsDialog(self, self.last_sale_id).exec()

        def show_last_receipt(self) -> None:
            if self.last_sale_id is None:
                QMessageBox.information(self, "ใบเสร็จ", "ยังไม่มีบิลในกะนี้")
                return
            dialog = QDialog(self)
            dialog.setWindowTitle("ใบเสร็จล่าสุด")
            dialog.setWindowFlag(Qt.WindowCloseButtonHint, True)
            dialog.setMinimumWidth(420)
            layout = QVBoxLayout(dialog)
            body = QLabel(receipt_for(service.db, self.last_sale_id))
            body.setStyleSheet("background:%s;padding:16px;font-family:'Menlo','Courier New';font-size:12px;" % PALETTE["surface"])
            layout.addWidget(body)
            close = QDialogButtonBox(QDialogButtonBox.Close)
            close.rejected.connect(dialog.reject)
            layout.addWidget(close)
            dialog.exec()

        def show_daily_sales(self) -> None:
            """Show the local terminal's sales without requiring a web server."""
            summary = service.daily_sales_summary()
            dialog = QDialog(self)
            dialog.setWindowTitle("ยอดขายวันนี้ · เครื่องนี้")
            dialog.setWindowFlag(Qt.WindowCloseButtonHint, True)
            dialog.setMinimumWidth(520)
            dialog.resize(560, 560)
            layout = QVBoxLayout(dialog)

            title = QLabel(f"สรุปยอดวันที่ {summary.report_date.strftime('%d/%m/%Y')} · ข้อมูลจาก SQLite เครื่องนี้")
            title.setObjectName("cardTitle")
            layout.addWidget(title)
            status = QLabel(
                f"{summary.transaction_count} บิล · ยกเลิก {summary.void_count} บิล · "
                f"รอ sync {summary.pending_sync_count} บิล"
            )
            status.setObjectName("cardHint")
            layout.addWidget(status)

            totals = QTableWidget(4, 2)
            totals.setHorizontalHeaderLabels(["รายการ", "ยอด (บาท)"])
            totals.verticalHeader().setVisible(False)
            totals.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
            for row, values in enumerate([
                ("ยอดขายก่อนส่วนลด", f"{summary.subtotal:,.2f}"),
                ("ส่วนลด", f"-{summary.discount_total:,.2f}"),
                ("VAT", f"{summary.vat_total:,.2f}"),
                ("ยอดขายสุทธิ", f"{summary.grand_total:,.2f}"),
            ]):
                totals.setItem(row, 0, QTableWidgetItem(values[0]))
                item = QTableWidgetItem(values[1])
                item.setTextAlignment(Qt.AlignRight | Qt.AlignVCenter)
                totals.setItem(row, 1, item)
            totals.setFixedHeight(190)
            layout.addWidget(totals)

            payment_text = ", ".join(f"{method}: {amount:,.2f}" for method, amount in summary.payments) or "ยังไม่มีรายการรับชำระ"
            payments = QLabel(f"รับชำระตามประเภท: {payment_text}")
            payments.setWordWrap(True)
            layout.addWidget(payments)

            rows = service.db.execute(
                """SELECT document_no, sale_datetime, grand_total, sync_status
                   FROM sales WHERE is_void = 0 AND date(sale_datetime, '+7 hours') = ?
                   ORDER BY sale_datetime DESC LIMIT 10""",
                (summary.report_date.isoformat(),),
            ).fetchall()
            recent = QTableWidget(len(rows), 4)
            recent.setHorizontalHeaderLabels(["บิลล่าสุด", "เวลา", "ยอด", "Sync"])
            recent.verticalHeader().setVisible(False)
            recent.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
            for row_number, sale in enumerate(rows):
                for column, value in enumerate([
                    sale["document_no"], str(sale["sale_datetime"])[11:16],
                    f"{Decimal(str(sale['grand_total'])):,.2f}", sale["sync_status"],
                ]):
                    recent.setItem(row_number, column, QTableWidgetItem(str(value)))
            layout.addWidget(recent)

            close = QDialogButtonBox(QDialogButtonBox.Close)
            close.rejected.connect(dialog.reject)
            layout.addWidget(close)
            dialog.exec()

        # ---------- วาดใหม่ ----------

        def refresh_order(self, keep_selection: bool = False) -> None:
            selected = self.order.selected_index
            self.table.blockSignals(True)
            self.table.setRowCount(0)
            for line in self.order.lines:
                row = self.table.rowCount()
                self.table.insertRow(row)
                name = line.name if not line.locked_qty else f"{line.name}  (ชั่ง)"
                for column, value in enumerate([
                    name, f"{line.qty:,.3f}".rstrip("0").rstrip("."),
                    f"{line.unit_price:,.2f}", f"{line.total:,.2f}",
                ]):
                    self.table.setItem(row, column, QTableWidgetItem(str(value)))
            if selected is not None and selected < self.table.rowCount():
                self.table.selectRow(selected)
            self.table.blockSignals(False)
            if not keep_selection:
                self.order.select(selected)

            rate = service.vat_rate()
            self.subtotal_label.setText(
                f"รวมสินค้า {self.order.subtotal():,.2f}    ส่วนลด {self.order.discount_total():,.2f}"
            )
            self.vat_label.setText(f"รวม VAT {rate:g}% แล้ว {self.order.vat_total(rate):,.2f} บาท")
            self.grand_label.setText(f"{self.order.grand_total():,.2f} ฿")
            if online is not None:
                pending = getattr(online.worker, "pending", None)
                pending = pending if pending is not None else service.pending_sync_count()
                link = "เชื่อม ERP" if getattr(online.worker, "online", True) else "ออฟไลน์"
                self.status.setText(
                    f"{f'กะ {self.shift_id}' if self.shift_id else 'พร้อมใช้งาน · ยังไม่ได้เริ่มขาย'} · "
                    f"{f'เงินทอนต้นกะ {self.opening_cash:,.2f} บาท · ' if self.opening_cash is not None else ''}"
                    f"{link} · บิลรอส่ง {pending} ใบ · โหมด {self.order.mode}"
                )
            else:
                self.status.setText(
                    f"{f'กะ {self.shift_id}' if self.shift_id else 'พร้อมใช้งาน · ยังไม่ได้เริ่มขาย'} · "
                    f"{f'เงินทอนต้นกะ {self.opening_cash:,.2f} บาท · ' if self.opening_cash is not None else ''}"
                    f"บิลรอส่ง {service.pending_sync_count()} ใบ · โหมด {self.order.mode}"
                )

    if app is None:
        raise RuntimeError("POS ต้องเริ่ม QApplication จาก main.py ก่อนเปิดหน้าต่าง")
    app.setStyleSheet(STYLE)
    window = PosWindow()
    window.setMinimumSize(960, 600)
    window.resize(1280, 820)
    window.showMaximized()
    if online is not None:
        app.aboutToQuit.connect(online.worker.stop)
    return window


def _cached_layout(db) -> dict:
    """Return only the published, cached schema; malformed data falls back safely."""
    row = db.execute("SELECT value FROM device_settings WHERE key = 'pos_layout'").fetchone()
    if not row:
        return {"schema": "popcentral-pos-layout", "version": 1, "components": []}
    try:
        value = json.loads(row["value"])
    except (TypeError, ValueError):
        return {"schema": "popcentral-pos-layout", "version": 1, "components": []}
    if value.get("schema") != "popcentral-pos-layout" or not isinstance(value.get("components"), list):
        return {"schema": "popcentral-pos-layout", "version": 1, "components": []}
    return value


def _cached_json_setting(db, key: str) -> dict | None:
    row = db.execute("SELECT value FROM device_settings WHERE key = ?", (key,)).fetchone()
    if not row:
        return None
    try:
        value = json.loads(row["value"])
    except (TypeError, ValueError):
        return None
    return value if isinstance(value, dict) else None
