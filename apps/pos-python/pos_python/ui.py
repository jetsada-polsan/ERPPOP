"""หน้าจอขาย PopCentral — แผงสินค้าและบิลแบบสองคอลัมน์ พร้อม numpad แก้บรรทัดที่เลือก

ชั้นนี้ทำหน้าที่วาดและรับปุ่มเท่านั้น การคิดเงินทั้งหมดอยู่ใน order.py กับ
services.py ซึ่งมีเทสต์ครอบอยู่ เพราะตัวเลขที่ผิดในชั้นหน้าจอจะไม่มีอะไรจับได้
"""
from __future__ import annotations

import json
import re
import sqlite3
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from string import Template

from .time_service import local_datetime_text
from .barcode import decode_scale_label, is_scale_product, load_scale_profiles, scale_cart_line
from .build_info import APP_VERSION
from .shift_float import cash, supports_float, FloatClose, z_float_text
from .api_client import LaravelPosClient
from . import device_status
from .mock_printer import active_paper_width, company_details, receipt_for, receipt_copies
from .order import ALL_CATEGORIES, DISCOUNT, PRICE, QTY, Order, OrderLine, categories, product_grid
from .config import DeviceConfig, load_device_config, normalize_server_url, save_device_config
from .printers import installed_printer_names, open_cash_drawer, print_text_to_windows_queue
from .promptpay import promptpay_payload, qr_matrix
from .promotions import active_promotions
from .services import PosService, money
from .sync_service import SyncService
from .settings_service import PrinterProfile, SettingsService

PALETTE = {
    # ชื่อและค่าตรงกับ token --erp-* ในระบบหลังบ้าน เพื่อให้ POS กับ ERP พูดภาษาสี
    # เดียวกัน เปลี่ยนธีมทีเดียวแล้วขยับตามกันทั้งสองฝั่ง
    "primary": "#1585c0",       # ฟ้า JET — เส้น accent, ตัวที่เลือกอยู่
    "primary_dark": "#0f4c75",  # ฟ้าเข้ม — แถบบน, หัวบิล
    "primary_ink": "#147db5",   # ใช้ตอนมีตัวหนังสือขาวทับ 4.54:1
    "primary_soft": "#eef4f9",
    "success": "#158662",       # เงินทอน — ความหมาย ไม่ใช่สีแบรนด์
    "success_light": "#bbf7d0",
    "success_soft": "#eef9f4",   # แถบคิวบิลด้านล่าง
    "success_muted": "#9fc9b9",  # ปุ่มรับเงินตอนยังไม่มีรายการ
    "danger_light": "#fecaca",
    "success_dark": "#0f6b4e",  # หน้าขายแบบ JET POS
    "primary_pressed": "#dcebf6",
    "pulse_line": "#eadde0",
    "row_alt": "#fafcfe",
    "pulse_bg": "#fcf7f8",
    "danger_soft": "#fff1ef",   # ป้ายออฟไลน์บนแถบน้ำเงินเข้ม
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
    "brand_navy": "#0a2540",    # MPM Food Group Deep Navy
    "payable_mint": "#57d9a3",  # สีเขียวนีออนสว่างสำหรับยอดสุทธิบนพื้นเข้ม
    "danger_btn": "#b91c1c",    # สีปุ่มเตือน/ลบในชุด Design System
    "danger_dark": "#991b1b",   # สีปุ่มเตือนเมื่อกด/วางเมาส์
}

DESIGN_SYSTEM_QSS_PATH = Path(__file__).resolve().parent / "assets" / "jet_pos_design_system.qss"


def load_design_system_qss() -> str:
    """Load the packaged QSS design system file so Qt applies it at startup."""
    if not DESIGN_SYSTEM_QSS_PATH.exists():
        return ""
    return DESIGN_SYSTEM_QSS_PATH.read_text(encoding="utf-8")

# ค่าเหล่านี้ใช้ชื่อเดียวกับ PosLayout::defaultRuntime() ของ Laravel
# เพื่อให้ layout ที่ผู้ดูแล Build จากเว็บส่งผลกับเครื่อง Python ได้โดยไม่ต้อง build
# installer ใหม่ทุกครั้ง
POS_LAYOUT_DEFAULT_RUNTIME = {
    "product_width": 45,
    "cart_width": 55,
    "product_rows": 3,
    "product_columns": 4,
    "density": "compact",
    "button_size": "small",
    "show_branch": True,
    "show_terminal": True,
    "show_seller": True,
    "show_shift": True,
}

POS_LAYOUT_DENSITY = {
    "compact": {"gap": 6, "padding": 7, "card": 104, "font": 12},
    "comfortable": {"gap": 8, "padding": 10, "card": 124, "font": 13},
    "roomy": {"gap": 12, "padding": 14, "card": 148, "font": 15},
}

POS_LAYOUT_BUTTON = {
    "small": {"height": 36, "font": 13, "padding": 7},
    "medium": {"height": 42, "font": 16, "padding": 9},
    "large": {"height": 54, "font": 19, "padding": 12},
}


def _layout_int(value, fallback: int, minimum: int, maximum: int) -> int:
    if value is None:
        value = fallback
    try:
        value = int(value)
    except (TypeError, ValueError):
        # Keep the same behavior as PHP's (int) cast + clamp for a malformed
        # published cache. Valid layouts are already rejected by the server.
        value = 0
    return max(minimum, min(maximum, value))


def _layout_bool(value, fallback: bool) -> bool:
    if isinstance(value, bool):
        return value
    if isinstance(value, str):
        if value.strip().lower() in {"false", "0", "no", "off"}:
            return False
        if value.strip().lower() in {"true", "1", "yes", "on"}:
            return True
    if value is None:
        return fallback
    return bool(value)


def normalize_pos_layout(value) -> dict:
    """Normalize the published layout before a malformed cache can reach Qt."""
    layout = value if isinstance(value, dict) else {}
    raw = layout.get("runtime") if isinstance(layout.get("runtime"), dict) else {}
    defaults = POS_LAYOUT_DEFAULT_RUNTIME
    # Layout version 1 was the first runtime rollout and used the old
    # 55/45, comfortable/medium screen. Treat that untouched baseline as a
    # migration marker so an existing terminal receives the customer-facing
    # layout on its first launch after this installer update. Published
    # versions or deliberately customized layouts remain untouched.
    layout_version = layout.get("layout_version", layout.get("version", 1))
    legacy_runtime = {
        "product_width": 55, "cart_width": 45, "product_rows": 3,
        "product_columns": 4, "density": "comfortable", "button_size": "medium",
        "show_branch": True, "show_terminal": True, "show_seller": True, "show_shift": True,
    }
    if str(layout_version).isdigit() and int(layout_version) <= 1 and raw == legacy_runtime:
        raw = {}
    product = _layout_int(raw.get("product_width"), defaults["product_width"], 30, 70)
    cart = _layout_int(raw.get("cart_width"), 100 - product, 30, 70)
    total = product + cart
    if total != 100:
        # PHP round() rounds positive .5 upward; Python round() uses bankers'
        # rounding, so use the explicit form to keep both runtimes identical.
        product = _layout_int(int(product / total * 100 + 0.5), defaults["product_width"], 30, 70)
    cart = 100 - product
    runtime = {
        "product_width": product,
        "cart_width": cart,
        "product_rows": _layout_int(raw.get("product_rows"), defaults["product_rows"], 1, 8),
        "product_columns": _layout_int(raw.get("product_columns"), defaults["product_columns"], 2, 8),
        "density": raw.get("density") if raw.get("density") in POS_LAYOUT_DENSITY else defaults["density"],
        "button_size": raw.get("button_size") if raw.get("button_size") in POS_LAYOUT_BUTTON else defaults["button_size"],
        "show_branch": _layout_bool(raw.get("show_branch"), defaults["show_branch"]),
        "show_terminal": _layout_bool(raw.get("show_terminal"), defaults["show_terminal"]),
        "show_seller": _layout_bool(raw.get("show_seller"), defaults["show_seller"]),
        "show_shift": _layout_bool(raw.get("show_shift"), defaults["show_shift"]),
    }
    normalized = dict(layout)
    normalized["runtime"] = runtime
    return normalized


def build_pairing_dialog(data_dir, app):
    """Pair a new terminal before showing the seller selection screen."""
    try:
        from PySide6.QtCore import Qt
        from PySide6.QtWidgets import QDialog, QDialogButtonBox, QFormLayout, QLabel, QLineEdit, QVBoxLayout, QHBoxLayout, QPushButton, QFrame
    except ImportError as error:
        raise RuntimeError("ยังไม่ได้ติดตั้ง PySide6") from error

    if app is None:
        raise RuntimeError("POS ต้องเริ่ม QApplication จาก main.py ก่อนเปิดหน้าต่าง")
    from .branding import logo_label, app_icon
    from .touch_dialogs import TouchMessage
    dialog = QDialog()
    dialog.setWindowIcon(app_icon())
    dialog.setWindowTitle("เชื่อมต่อ JET POS")
    dialog.setWindowFlag(Qt.WindowCloseButtonHint, True)
    dialog.setObjectName("settingsBackdrop")
    dialog.resize(720, 540)
    dialog.setStyleSheet(STYLE)
    outer = QVBoxLayout(dialog)
    outer.setContentsMargins(20, 20, 20, 20)
    card = QFrame()
    card.setObjectName("settingsPopup")
    outer.addWidget(card)
    form = QFormLayout(card)
    form.setContentsMargins(28, 24, 28, 24)
    form.setSpacing(18)
    form.addRow(logo_label(height=64))
    title = QLabel("ตั้งค่าเครื่องครั้งแรก")
    title.setStyleSheet(f"font-size:24px;font-weight:800;background:{PALETTE['primary_dark']};color:{PALETTE['surface']};padding:16px;border-radius:12px;")
    hint = QLabel(
        "1. ที่ ERP: POS / หน้าร้าน → จัดการเครื่อง POS → กด เพิ่มเครื่อง ที่สาขานี้\n"
        "2. คัดลอก Token ที่ขึ้นบนสุดของหน้า แล้ววางด้านล่าง\n"
        "3. กด ตรวจสอบและเริ่มใช้งาน"
    )
    hint.setWordWrap(True)
    form.addRow(title)
    form.addRow(hint)
    server = QLineEdit("https://erp.popstarcenter.com")
    server.setPlaceholderText("เช่น https://erp.example.com")
    token = QLineEdit()
    token.setPlaceholderText("วาง Token ที่คัดลอกจาก ERP")
    token.setEchoMode(QLineEdit.Password)
    form.addRow("ที่อยู่ ERP", server)
    token.setObjectName("pairingToken")
    token.setMinimumHeight(56)
    server.setMinimumHeight(56)
    controls = QHBoxLayout()
    controls.addWidget(token, 1)
    show = QPushButton("แสดง")
    show.setCheckable(True)
    show.toggled.connect(lambda on: token.setEchoMode(QLineEdit.Normal if on else QLineEdit.Password))
    paste = QPushButton("วาง")
    paste.clicked.connect(lambda: (token.clear(), token.setText(app.clipboard().text().strip())))
    for button in (show, paste):
        button.setMinimumHeight(56)
        controls.addWidget(button)
    form.addRow("Token", controls)
    buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
    buttons.button(QDialogButtonBox.Ok).setText("ตรวจสอบและเริ่มใช้งาน")
    buttons.button(QDialogButtonBox.Cancel).setText("ปิด")
    buttons.button(QDialogButtonBox.Ok).setObjectName("primary")
    for button in buttons.buttons():
        button.setMinimumHeight(56)
    form.addRow(buttons)

    def pair() -> None:
        url = normalize_server_url(server.text())
        device_token = token.text().strip()
        if not url or not device_token:
            TouchMessage.warning(dialog, "ข้อมูลไม่ครบ", "กรอกที่อยู่ ERP และวาง Token ให้ครบ")
            return
        try:
            profile = LaravelPosClient(url, device_token, allow_insecure=url.startswith("http://")).get("/api/pos/ping")
            if not profile.get("success", False):
                raise RuntimeError(profile.get("message", "ERP ไม่ยืนยันเครื่องนี้"))
            save_device_config(data_dir, DeviceConfig(url, device_token, url.startswith("http://")))
            TouchMessage.information(
                dialog,
                "เชื่อมต่อสำเร็จ",
                f"สาขา: {profile.get('branch_name', '-')}\n"
                f"เครื่อง: {(profile.get('device') or {}).get('terminal_code', '-')}\n\n"
                "กำลังเปิด POS และ sync ข้อมูลครั้งแรก",
            )
            dialog.accept()
        except Exception as error:
            TouchMessage.warning(dialog, "เชื่อมต่อไม่สำเร็จ", f"{error}\n\nตรวจว่าเครื่องต่อเน็ต Token คัดลอกครบ และ ERP ยังไม่ได้ออก Token ใหม่ทับ")

    buttons.accepted.connect(pair)
    buttons.rejected.connect(dialog.reject)
    return dialog


def run_pairing_wizard(data_dir, app) -> bool:
    dialog = build_pairing_dialog(data_dir, app)
    # PySide6 exposes the result enum on QDialog.DialogCode, not on the
    # instance.  A fresh installation always enters this path before a
    # device config exists, so using ``dialog.Accepted`` made first launch
    # fail before the user could paste the ERP token.
    from PySide6.QtWidgets import QDialog
    result = dialog.exec() == QDialog.DialogCode.Accepted
    app.processEvents()
    return result

# QSS ใช้ปีกกาเป็นไวยากรณ์ เลยแทนค่าด้วย $name แทน .format()
_STYLE_TEMPLATE = Template("""
/* โทน POS หน้าร้านแบบเครื่องแคชเชียร์ — PopCentral ใช้ token เดียวกับ ERP */
QMainWindow, QDialog, QWidget { background: $bg; color: $text; font-size: 14px; }

/* ป้ายที่วางบนแถบสีต้องโปร่ง ไม่งั้นกินสีพื้นแอปมาเป็นแผ่นขาวทับแถบ */
#brandBar { background: $primary_dark; border-bottom: 2px solid $primary; }
#brandName, #brandMark, #brandRight,
#totalLabel, #vatLine, #grandTotal { background: transparent; }
#brandName { color: $surface; font-size: 16px; font-weight: 800; padding: 10px 0; }
#brandMark { color: $surface; border: 2px solid $surface; border-radius: 6px; padding: 5px 9px; font-weight: 800; }
#brandRight { color: $surface; font-size: 13px; }
#runtimeChip { color: $surface; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.28); border-radius: 6px; padding: 4px 7px; font-size: 11px; font-weight: 700; }
#runtimeChip[shiftOpen="true"] { color: $success_light; border-color: $success_light; background: rgba(20,122,85,.35); }
#scanHint { background: transparent; color: $muted; font-size: 11px; padding: 0 2px; }
QToolButton#dialogClose { color: $surface; background: transparent; border: 0; padding: 3px 10px; font-size: 24px; font-weight: 800; }
QToolButton#dialogClose:hover { background: rgba(255,255,255,.16); border-radius: 6px; }

#primarySplitter { background: $bg; }
QSplitter::handle { background: $border; }
QSplitter::handle:hover { background: $primary; }

#card { background: $surface; border: 1px solid $border; border-radius: 7px; }
#cardTitle { font-size: 19px; font-weight: 800; color: $primary_dark; }
#cardHint, #muted { color: $muted; font-size: 13px; }
#note { border-left: 4px solid $warning; background: $warning_soft; color: $warning_ink; font-size: 13px; padding: 12px; }

/* หน้าตั้งค่าแบบ popup: ฉากหลังโปร่ง การ์ดขาวขอบมน หัวสีเข้ม */
QDialog#settingsBackdrop { background: transparent; }
#settingsPopup { background: $surface; border: 1px solid $border; border-radius: 16px; }
#settingsPopup QLabel, #settingsPopup QCheckBox, #settingsPopup QScrollArea, #settingsPopup QScrollArea > QWidget > QWidget { background: transparent; }
#settingsPopup #card > QWidget { background: transparent; }
#settingsPopup QCheckBox::indicator { width: 18px; height: 18px; border: 1px solid $border; border-radius: 4px; background: $surface; }
#settingsPopup QCheckBox::indicator:checked { background: $primary; border-color: $primary; }
QWidget#settingsBody { background: $surface; border-bottom-right-radius: 16px; }
QWidget#settingsHeader { background: $primary_dark; border-top-left-radius: 16px; border-top-right-radius: 16px; }
#settingsTitle { color: $surface; font-size: 18px; font-weight: 800; }
#settingsSubtitle { color: rgba(255,255,255,.72); font-size: 12.5px; }
QWidget#settingsMenu { background: $bg; border-right: 1px solid $border; border-bottom-left-radius: 16px; }
#settingsPopup #card { background: $surface; }
#settingsPopup QLineEdit, #settingsPopup QComboBox { background: $surface; }
#settingsPopup QPushButton { background: $surface; }
#settingsPopup QPushButton#navItem { background: transparent; }
#settingsPopup QPushButton#navItem:checked { background: $surface; }

/* เมนูซ้ายของหน้าตั้งค่า — ตัวที่เลือกอยู่มีขีดฟ้าหน้าเหมือนในภาพร่าง */
#navItem { background: transparent; border: 0; border-left: 4px solid transparent; border-radius: 0;
           text-align: left; padding: 12px 14px; color: $text; font-size: 14.5px; }
#navItem:hover { background: $primary_soft; }
#navItem:checked { background: $primary_soft; color: $primary_dark; border-left: 4px solid $primary; font-weight: 700; }

QPushButton { background: $surface; border: 1px solid $border; border-radius: 7px; padding: $button_padding 14px; font-size: $button_font; }
QPushButton:hover { background: $primary_soft; border-color: $primary; }
QPushButton:checked { background: $primary_ink; color: $surface; border-color: $primary_ink; }
QPushButton#primary, QPushButton#payBtn { background: $primary_ink; color: $surface; border-color: $primary_ink; font-weight: 700; }
QPushButton#primary:hover, QPushButton#payBtn:hover { background: $primary_dark; border-color: $primary_dark; }
QPushButton#payBtn { font-size: $pay_font; padding: $pay_padding; }
QPushButton#voidBtn { color: $danger; }
QToolButton#tile {
    text-align: left;
    padding: $tile_padding;
    background: $surface;
    border: 1px solid $border;
    border-radius: 8px;
    font-size: $tile_font;
    font-weight: 650;
}
QToolButton#tile:hover { background: $primary_soft; border-color: $primary; }
QToolButton#tile:pressed { background: $field; }
QScrollArea#productScroll { background: transparent; border: 0; }
#productPanel { background: $bg; }
#productHead { background: $primary_soft; border: 1px solid $border; border-radius: 7px; padding: 7px 10px; }
#productTitle { color: $primary_dark; font-size: 18px; font-weight: 800; }
#productHint { color: $muted; font-size: 12px; }

QLineEdit, QComboBox { background: $surface; border: 1px solid $field; border-radius: 7px; padding: 9px 11px; font-size: 14.5px; }
QLineEdit:focus, QComboBox:focus { border-color: $primary; }

/* จอ POS 14-15 นิ้วมักเป็น 1366x768 — ลดเฉพาะ chrome ของหน้าจอ
   เพื่อเก็บพื้นที่ไว้ให้รายการสินค้าและบิล ไม่เปลี่ยนขนาดตัวเลขทางธุรกิจ */
QMainWindow[compact="true"] #brandName { font-size: 16px; padding: 8px 0; }
QMainWindow[compact="true"] #brandRight { font-size: 13px; }
QMainWindow[compact="true"] QPushButton { padding: $compact_button_padding 10px; font-size: 16px; min-height: 48px; }
QMainWindow[compact="true"] QPushButton#payBtn { font-size: 20px; padding: $pay_padding 12px; min-height: 58px; }
QMainWindow[compact="true"] QLineEdit, QComboBox { padding: 6px 10px; font-size: 16px; }
QMainWindow[compact="true"] QToolButton#tile { padding: $tile_padding; font-size: $tile_font; }
QMainWindow[compact="true"] #grandTotal { font-size: 34px; }
QMainWindow[compact="true"] #orderHead { padding: 6px 10px; }
QMainWindow[compact="true"] #orderHead QLabel { font-size: 14px; }
QMainWindow[compact="true"] QPushButton#headerAction { padding: 4px 8px; font-size: 14px; }
QMainWindow[compact="true"] #totalBox { padding: 8px 12px; }
QMainWindow[compact="true"] #saleKeypad QPushButton { min-height: $keypad_height; padding: 4px 6px; font-size: 16px; }

#orderPanel { background: $surface; border-right: 1px solid $border; }
#orderHead { background: $primary_dark; color: $surface; padding: 6px 10px; font-weight: 700; }
#orderHead QLabel { background: transparent; color: $surface; font-size: 13px; font-weight: 700; }
QPushButton#headerAction { background: transparent; color: $surface; border-color: rgba(255,255,255,0.45); padding: 3px 7px; font-size: 12px; }
QPushButton#headerAction:hover { background: rgba(255,255,255,0.12); border-color: $surface; }
QPushButton#menuAction { background: rgba(255,255,255,0.16); color: $surface; border: 1px solid rgba(255,255,255,0.7); border-radius: 7px; padding: 6px 14px; font-size: 15px; font-weight: 800; }
QPushButton#menuAction:hover, QPushButton#menuAction:pressed { background: rgba(255,255,255,0.28); }
QMenu#bigMenu { font-size: 16px; padding: 6px; }
QMenu#bigMenu::item { padding: 12px 28px; min-width: 200px; }
#totalBox { background: $primary_dark; color: $surface; padding: 8px 12px; }
#totalBox QLabel { background: transparent; }
 #grandTotal { font-size: 26px; font-weight: 900; color: $surface; }
#totalLabel, #vatLine { color: $preview; font-size: 11px; }

QTableWidget::item:selected { background: $primary_soft; color: $text; }
QTableWidget::item { padding: 4px; }

QTableWidget { background: $surface; border: 0; }
QHeaderView::section { background: $primary_soft; border: 0; border-bottom: 1px solid $border; padding: 5px; font-weight: 700; }
 #saleKeypad QPushButton { min-height: $keypad_height; padding: 3px 6px; font-size: $compact_button_font; }

/* กระดาษใบเสร็จวางบนพื้นเทาเหมือนวางบนโต๊ะ */
#receiptBg { background: $preview; border-radius: 6px; }
#receiptPaper { background: $surface; padding: 20px; font-size: 12px; color: $paper_ink; }
#statusBar { color: $muted; font-size: 11px; padding: 3px 10px; }

#shiftAmount { font-size: 24px; font-weight: 800; padding: 8px 12px; }
#shiftSummary { background: $primary_soft; border: 1px solid $border; border-radius: 7px; padding: 12px 14px; font-size: 17px; font-weight: 700; }
#shiftKeypad QPushButton { min-height: 40px; font-size: 18px; font-weight: 700; }

/* ===== หน้าขายแบบ JET POS (Vue) — วางท้ายสุดให้ทับกฎเก่า ===== */
QWidget#jetTopbar { background: $primary_dark; border-bottom: 3px solid $primary; }
#jetTopbar QLabel { background: transparent; color: $surface; }
#jetBrand { font-size: 18px; font-weight: 800; }
#jetTopbar QLabel#jetTagline { font-size: 10px; font-weight: 800; color: rgba(255,255,255,.72); }
QWidget#jetContext { background: transparent; border-left: 1px solid rgba(255,255,255,.22); }
#jetTopbar QLabel#jetContextCaption { font-size: 11px; font-weight: 800; color: rgba(255,255,255,.7); }
#jetContextValue { font-size: 13px; font-weight: 700; }
#jetTopbar QLabel#jetStatus { padding: 6px 10px; border-radius: 7px; font-size: 12px; font-weight: 800;
             border: 1px solid $danger_light; color: $danger_light; background: rgba(201,33,45,.35); }
#jetTopbar QLabel#jetStatus[online="true"] { border-color: $success_light; color: $success_light; background: rgba(20,122,85,.35); }
QPushButton#jetTopButton { background: rgba(255,255,255,.10); color: $surface; border: 1px solid rgba(255,255,255,.35);
                           border-radius: 7px; padding: 4px 10px; font-size: 13px; font-weight: 700; }
QPushButton#jetTopButton:hover { background: rgba(255,255,255,.22); border-color: $surface; }

QWidget#catalog { background: $bg; }
#catalog QLabel { background: transparent; }
#sectionCaption { color: $muted; font-size: 12px; font-weight: 800; }
#sectionTitle { color: $text; font-size: 15px; font-weight: 800; }
#catalogCount { color: $primary; font-size: 14px; font-weight: 800; }
QLineEdit#searchRow { background: $surface; border: 1px solid $border; border-radius: 8px; padding: 0 14px; font-size: 15px; font-weight: 600; }
QLineEdit#searchRow:focus { border: 2px solid $primary; }
QScrollArea#productScroll, QWidget#productGrid { background: $bg; border: 0; }
QPushButton#productTile { background: $surface; border: 1px solid $border; border-radius: 8px; padding: 0; text-align: left; }
QPushButton#productTile:hover { background: $primary_soft; border-color: $primary; }
QPushButton#productTile:pressed { background: $primary_pressed; }
#productTile QLabel { background: transparent; }
#productCode { color: $muted; font-size: 12px; }
#productName { color: $text; font-size: 13px; font-weight: 700; }
#productUnit { color: $muted; font-size: 12px; }
#productPrice { color: $primary_dark; font-size: 16px; font-weight: 800; }

QWidget#cartPanel { background: $surface; border-left: 1px solid $border; }
QWidget#cartTitle { background: $primary_dark; }
#cartTitle QLabel { background: transparent; color: $surface; }
#cartTitle QLabel#cartTitleSmall { font-size: 11px; font-weight: 700; color: rgba(255,255,255,.8); }
#cartTitleMain { font-size: 15px; font-weight: 800; }
QPushButton#cartTitleButton { background: rgba(255,255,255,.12); color: $surface; border: 1px solid rgba(255,255,255,.4); border-radius: 7px; padding: 4px 12px; }
QWidget#shiftPulse { background: $pulse_bg; border-bottom: 1px solid $border; }
QWidget#pulseCell { background: transparent; border-right: 1px solid $pulse_line; }
#pulseCell QLabel { background: transparent; }
#pulseCaption { color: $muted; font-size: 11px; font-weight: 700; }
#pulseValue { color: $text; font-size: 13px; font-weight: 800; }
QTableWidget#cartTable { background: $surface; alternate-background-color: $row_alt; border: 0; font-size: 13px;
                         selection-background-color: $primary_soft; selection-color: $text; }
QTableWidget#cartTable::item { border-bottom: 1px solid $border; padding: 2px 4px; }
#cartTable QHeaderView::section { background: $primary_soft; color: $text; border: 0; border-bottom: 1px solid $border;
                                  padding: 5px 4px; font-size: 13px; font-weight: 800; }
#cartTable QWidget { background: transparent; }
QPushButton#qtyButton { background: $surface; border: 1px solid $border; border-radius: 6px; padding: 0; font-size: 17px; font-weight: 800; }
QPushButton#qtyButton:hover { border-color: $primary; background: $primary_soft; }
#qtyValue { font-size: 14px; font-weight: 800; }
#cartTable QPushButton#voidBtn { background: transparent; border: 0; color: $danger; font-size: 15px; font-weight: 800; padding: 0; }
#cartTable QPushButton#voidBtn:hover { background: $danger_soft; border-radius: 6px; }
#cartEmpty { background: $surface; color: $muted; font-size: 14px; }
QPushButton#cartSecondary { background: $surface; border: 1px solid $border; border-radius: 7px; padding: 8px; font-size: 13px; }
QWidget#totalBox { background: $primary_dark; padding: 0; }
#totalBox QLabel { background: transparent; color: rgba(255,255,255,.85); font-size: 13px; }
#totalBox QLabel#grandTotal { color: $surface; font-size: 34px; font-weight: 800; }
QPushButton#payBtn { background: $success; border: 0; border-radius: 8px; color: $surface; font-size: 18px; font-weight: 800; padding: 10px; }
QPushButton#payBtn:hover { background: $success_dark; }
QPushButton#payBtn:disabled { background: $success_muted; }
#queueBar { background: $success_soft; color: $success; font-size: 12px; font-weight: 700; padding: 6px 14px; border-top: 1px solid $border; }
QPushButton#keypadKey { font-size: 20px; font-weight: 700; }

""")

def _layout_px(css: dict, name: str, fallback: int) -> int:
    value = css.get(name) if isinstance(css, dict) else None
    match = re.fullmatch(r"\s*([0-9]+(?:\.[0-9]+)?)px\s*", str(value or ""))
    if not match:
        return fallback
    return max(0, int(float(match.group(1))))


def _layout_metrics(runtime: dict | None = None, css: dict | None = None) -> dict:
    normalized = normalize_pos_layout({"runtime": runtime or {}})["runtime"]
    density = POS_LAYOUT_DENSITY[normalized["density"]]
    button = POS_LAYOUT_BUTTON[normalized["button_size"]]
    css = css if isinstance(css, dict) else {}
    return {
        "gap": _layout_px(css, "--pos-grid-gap", density["gap"]),
        "padding": _layout_px(css, "--pos-grid-padding", density["padding"]),
        "card": _layout_px(css, "--pos-card-min-height", density["card"]),
        "font": _layout_px(css, "--pos-card-font-size", density["font"]),
        "button_height": _layout_px(css, "--pos-button-min-height", button["height"]),
        "button_font": _layout_px(css, "--pos-button-font-size", button["font"]),
        "button_padding": _layout_px(css, "--pos-button-padding", button["padding"]),
    }


def _style_for_layout(runtime: dict | None = None, css: dict | None = None) -> str:
    metrics = _layout_metrics(runtime, css)
    values = {
        **PALETTE,
        "button_height": f'{metrics["button_height"]}px',
        "button_padding": f'{metrics["button_padding"]}px',
        "button_font": f'{metrics["button_font"]}px',
        "compact_button_padding": f'{max(4, metrics["button_padding"] - 2)}px',
        "compact_button_font": f'{max(12, metrics["button_font"] - 2)}px',
        "keypad_height": f'{max(26, metrics["button_height"] - 8)}px',
        "pay_padding": f'{metrics["button_padding"] + 4}px',
        "pay_font": f'{max(15, metrics["button_font"] + 2)}px',
        "tile_padding": f'{metrics["padding"]}px',
        "tile_font": f'{metrics["font"]}px',
    }
    design_qss = Template(load_design_system_qss()).safe_substitute(values)
    return _STYLE_TEMPLATE.substitute(values) + "\n" + design_qss


STYLE = _style_for_layout(POS_LAYOUT_DEFAULT_RUNTIME)

SECTION_HINTS = {
    "สถานะเครื่อง": "เครื่องนี้ผูกกับสาขาไหน เชื่อม ERP อยู่ไหม และมีอะไรค้างส่ง",
    "ผูกเครื่องกับ ERP": "ใส่ที่อยู่ ERP และ Token จาก ERP > POS / หน้าร้าน > จัดการเครื่อง POS > เพิ่มเครื่อง",
    "เครื่องพิมพ์และใบเสร็จ": "ตั้งค่าเครื่องพิมพ์และดูตัวอย่างใบเสร็จก่อนบันทึก",
    "เครื่องชั่ง / Barcode": "รูปแบบป้ายมาจาก ERP เครื่องนี้ไม่เดารูปแบบเอง",
    "สำรองฐานข้อมูล": "สำรองฐานข้อมูลในเครื่องเป็นไฟล์เดียว ไม่แตะยอดขายหรือคิวที่ค้าง",
    "งานพิมพ์ล่าสุด": "งานพิมพ์ล่าสุดและจำนวนครั้งที่พยายามส่ง",
}

STATUS_COLORS = {"ok": ("#e7f6ec", "#166534"), "warn": ("#fff7e0", "#92400e"), "bad": ("#fde8e8", "#991b1b")}
DATASET_ICONS = {"ok": "●", "failed": "●", "running": "◌", "never": "○"}
DATASET_COLORS = {"ok": "#16a34a", "failed": "#dc2626", "running": "#d97706", "never": "#9ca3af"}

NUMPAD_KEYS = [
    ["7", "8", "9"],
    ["4", "5", "6"],
    ["1", "2", "3"],
    [".", "0", "backspace"],
]

PRODUCT_TILE_COLUMNS = 4


def parse_weight(text: str) -> float:
    """รับน้ำหนักเป็นกิโลกรัม แปลงเลขไทยที่พิมพ์มาเป็นเลขอารบิก"""
    cleaned = text.strip().translate(str.maketrans("๐๑๒๓๔๕๖๗๘๙", "0123456789"))
    try:
        weight = round(float(cleaned), 3)
    except ValueError:
        raise ValueError("กรอกน้ำหนักเป็นตัวเลข เช่น 1.250") from None
    if not 0.001 <= weight <= 999999.999:
        raise ValueError("น้ำหนักต้องมากกว่า 0")
    return weight


def cashier_error(error):
    text = str(error)
    if re.search(r"token|sync|preview|payload|outbox|server_id|https?://|traceback|sql|authorization", text, re.I) or not re.search(r"[ก-๙]", text):
        return "ทำรายการไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ หากยังไม่ได้ให้ผู้ดูแลตรวจที่หน้าตั้งค่าเครื่อง"
    return text


def sale_status_label(status):
    return {"synced": "ส่งแล้ว", "pending": "รอส่ง", "failed": "ส่งไม่สำเร็จ"}.get(status, "รอส่ง")


def receipt_action_problem(sale, shift, online):
    if not shift:
        return "ต้องเปิดกะก่อนจึงยกเลิกบิลหรือรับคืนสินค้าได้ กดเข้างานแล้วเปิดกะก่อน"
    if sale['is_void']:
        return "บิลนี้ยกเลิกแล้ว ให้เลือกบิลอื่น"
    if not online:
        return "ต้องต่ออินเทอร์เน็ตก่อน ตรวจการเชื่อมต่อแล้วลองอีกครั้ง"
    if not shift['server_id']:
        return "กะนี้ยังส่ง ERP ไม่สำเร็จ รอให้ส่งข้อมูลแล้วลองอีกครั้ง"
    if not sale['server_receipt_no'] or sale['sync_status'] != 'synced':
        return "บิลนี้ยังส่ง ERP ไม่สำเร็จ รอให้ส่งบิลแล้วลองอีกครั้ง หากยังไม่ได้ให้ผู้ดูแลตรวจรายการรอส่ง"
    return None


def run_ui(service: PosService, online=None, data_dir=None, app=None):
    # Windows ภาษาไทยทำให้ช่องตัวเลขแสดงเลขไทย POS ใช้เลขอารบิกเสมอ
    from PySide6.QtCore import QLocale
    QLocale.setDefault(QLocale.c())
    # เครื่องที่ผูก ERP แล้วใช้ branch/terminal จริงจาก ping; เครื่อง demo ใช้ค่าเดิม
    branch_id = int(online.branch_id) if (online is not None and online.branch_id) else 1
    terminal_id = (online.terminal_id if (online is not None and online.terminal_id) else "PY-TEST-01")
    layout_config = _cached_layout(service.db)
    layout_config = normalize_pos_layout(layout_config)
    layout_runtime = layout_config["runtime"]
    layout_metrics = _layout_metrics(layout_runtime, layout_config.get("css"))
    try:
        from .branding import logo_label, app_icon
        from .touch_dialogs import TouchMessage
        from PySide6.QtCore import QTimer, QSize, Qt, QEvent
        from PySide6.QtGui import QColor, QFont, QFontDatabase, QImage, QKeySequence, QPainter, QPixmap, QShortcut
        from PySide6.QtWidgets import (
            QButtonGroup, QCheckBox, QComboBox, QDialog, QDialogButtonBox, QFormLayout, QGridLayout,
            QHBoxLayout, QHeaderView, QLabel, QLineEdit, QMainWindow, QPushButton, QMenu,
            QStackedWidget, QFrame, QGraphicsDropShadowEffect, QScrollArea, QSizePolicy, QSplitter, QTableWidget, QTableWidgetItem, QToolButton, QVBoxLayout, QWidget,
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
    runtime_style = _style_for_layout(layout_runtime, layout_config.get("css"))

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

    from .touch_dialogs import TouchDialog, NumPad, PinPad, ValueDialog, ChoiceDialog, TextDialog

    class SellerSelectionDialog(TouchDialog):
        def __init__(self):
            super().__init__(title="เลือกคนขาย", subtitle="เลือกชื่อเพื่อเริ่มขาย")
            self.cashier = None
            self.setWindowTitle(f"JET POS v{APP_VERSION} — เลือกคนขาย")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setWindowFlag(Qt.WindowStaysOnTopHint, True)
            self.setWindowModality(Qt.WindowModal)
            self.setMinimumWidth(480)
            form = QFormLayout()
            self.body.addLayout(form)
            hint = QLabel(
                "เลือกชื่อคนขาย ใส่ PIN แล้วใส่เงินทอนเพื่อเปิดกะ"
                if service.requires_cashier_pin()
                else "เลือกชื่อคนขาย แล้วใส่เงินทอนเพื่อเปิดกะ\nไม่ต้องกรอกรหัสหรือ PIN"
            )
            hint.setObjectName("note")
            hint.setWordWrap(True)
            self.cashier_select = QComboBox()
            self.cashier_select.setMinimumHeight(48)
            self.connection_status = QLabel()
            self.connection_status.setObjectName("cardHint")
            self.connection_status.setWordWrap(True)
            refresh = QPushButton("อัปเดตรายชื่อ")
            refresh.setObjectName("btnTouchSecondary")
            refresh.setMinimumHeight(52)
            refresh.clicked.connect(self.refresh_cashiers)
            submit = QPushButton("เลือกชื่อและเปิดกะ")
            submit.setObjectName("btnCheckoutPrimary")
            submit.setMinimumHeight(56)
            submit.clicked.connect(self.choose)
            cancel = QPushButton("ยกเลิก")
            cancel.setObjectName("btnTouchSecondary")
            cancel.setMinimumHeight(52)
            cancel.clicked.connect(self.reject)
            self.refresh_cashiers()
            form.addRow(hint)
            form.addRow("ชื่อคนขาย", self.cashier_select)
            form.addRow(self.connection_status)
            actions = QHBoxLayout()
            actions.addWidget(refresh)
            actions.addStretch(1)
            actions.addWidget(cancel)
            actions.addWidget(submit)
            form.addRow(actions)

        def refresh_cashiers(self) -> None:
            sync_error = None
            if online is not None and online.online:
                try:
                    online.provisioning.pull_cashiers(online.branch_id)
                except Exception as error:
                    sync_error = str(error)

            rows = service.db.execute(
                """SELECT id, code, name, server_id FROM local_cashiers
                   WHERE active = 1 AND revoked_at IS NULL AND is_seller = 1 ORDER BY name, code"""
            ).fetchall()
            self.cashier_select.clear()
            self.cashier_select.addItem("เลือกชื่อคนขาย", None)
            for cashier in rows:
                # แสดงเฉพาะชื่อบนหน้าขาย ส่วน code/server_id ใช้ภายในเพื่อผูกยอดขายให้ถูกคน
                self.cashier_select.addItem(str(cashier["name"] or cashier["code"]), int(cashier["id"]))
            if len(rows) == 1:
                self.cashier_select.setCurrentIndex(1)

            if rows:
                mode = "เชื่อมต่อ ERP แล้ว" if online is not None and online.online else "โหมดออฟไลน์"
                message = f"{mode} · เลือกได้ {len(rows)} คน"
                if sync_error:
                    message += "\nโหลดรายชื่อใหม่ไม่ได้ ใช้รายชื่อที่มีในเครื่องก่อน หรือตรวจการเชื่อมต่อแล้วกดอัปเดตรายชื่อ"
            else:
                message = "ยังไม่พบรายชื่อคนขาย · กดอัปเดตรายชื่อหรือตรวจการผูกเครื่องกับ ERP"
                if sync_error:
                    message += "\nตรวจการเชื่อมต่อแล้วกดอัปเดตรายชื่ออีกครั้ง"
            self.connection_status.setText(message)

        def choose(self) -> None:
            local_id = self.cashier_select.currentData()
            if local_id is None:
                TouchMessage.information(self, "เลือกคนขาย", "เลือกชื่อคนขายก่อนเปิดกะ")
                return
            self.cashier = service.db.execute(
                "SELECT * FROM local_cashiers WHERE id = ? AND active = 1 AND revoked_at IS NULL",
                (int(local_id),),
            ).fetchone()
            if self.cashier is None:
                TouchMessage.warning(self, "เลือกคนขายไม่ได้", "รายชื่อคนขายนี้ถูกปิดใช้งานแล้ว กรุณาอัปเดตรายชื่อ")
                return
            self.accept()

    class OpeningShiftDialog(TouchDialog):
        """หน้าต่างเปิดกะที่ไม่ถูก On-Screen Keyboard บัง และกดยอดเงินทอนได้ง่าย"""

        def __init__(self, parent):
            super().__init__(parent, "เปิดกะขาย", "เปิดกะ: นับเงินในลิ้นชักแล้วใส่ยอด")
            self.opening_cash = Decimal("0.00")
            self.policy = getattr(parent, "shift_policy", {})
            self.opening_note = ""
            self.setWindowTitle("เปิดกะขาย")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setWindowFlag(Qt.WindowStaysOnTopHint, True)
            self.setWindowModality(Qt.WindowModal)
            self.setMinimumWidth(400)
            self.resize(480, 700)

            layout = self.body
            layout.setContentsMargins(10, 10, 10, 10)
            layout.setSpacing(6)
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

            self.note = QLineEdit()
            self.note.setPlaceholderText("เหตุผลเมื่อยอดรับกะต่างจากเงินทอนยกมา")
            self.note.setMaxLength(500)
            self.note.setStyleSheet("min-height:38px; font-size:15px;")
            layout.addWidget(self.note)
            self.pad = NumPad(self.amount)
            layout.addWidget(self.pad)
            initial = self.policy.get('carried_float')
            if initial is None:
                initial = self.policy.get('default_float', '0')
            self.amount.setText(str(cash(initial)))
            open_btn = self.confirm("เปิดกะขาย", self.accept_value)
            open_btn.setObjectName("btnCheckoutPrimary")
            open_btn.setMinimumHeight(52)

            # Put focus on the amount immediately so a touch keyboard or keypad
            # can be used without an extra click before opening the shift.
            self.amount.setFocus()
            reprint = QPushButton("พิมพ์ Z Report กะล่าสุดซ้ำ")
            reprint.setObjectName("btnTouchSecondary")
            reprint.setMinimumHeight(48)
            reprint.setStyleSheet("min-height:48px; font-size:16px;")
            reprint.clicked.connect(parent.show_z_report)
            layout.addWidget(reprint)

        def showEvent(self, event) -> None:
            super().showEvent(event)
            self.amount.setFocus()
            self.amount.selectAll()

        def press(self, key: str) -> None:
            self.pad.press(key)

        def value(self) -> Decimal:
            return cash(self.amount.text().strip() or "0")

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
                TouchMessage.warning(self, "จำนวนเงินไม่ถูกต้อง", "กรอกจำนวนเงินเป็นตัวเลขเท่านั้น")
                self.amount.setFocus()
                return
            if value < 0:
                TouchMessage.warning(self, "จำนวนเงินไม่ถูกต้อง", "เงินทอนตั้งต้นต้องไม่ติดลบ")
                return
            carried = self.policy.get('carried_float')
            self.opening_note = self.note.text().strip()
            if carried is not None and value != cash(carried) and not self.opening_note:
                TouchMessage.warning(self, "ต้องระบุเหตุผล", "ยอดรับกะต่างจากเงินทอนยกมา กรุณาระบุเหตุผล")
                return
            self.opening_cash = value
            self.accept()

    class ExistingShiftDialog(TouchDialog):
        """Make an open shift explicit instead of silently skipping opening cash."""

        def __init__(self, parent, shift):
            super().__init__(parent, "พบกะเดิม", "เลือกทำกะต่อหรือปิดกะ")
            self.close_existing = False
            self.setWindowTitle("พบกะที่ยังเปิดอยู่")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setWindowFlag(Qt.WindowStaysOnTopHint, True)
            self.setWindowModality(Qt.WindowModal)
            self.setMinimumWidth(420)

            layout = self.body
            title = QLabel("เครื่องนี้มีกะเดิมที่ยังไม่ได้ปิด")
            title.setStyleSheet("font-size:19px;font-weight:800")
            detail = QLabel(
                f"เปิดเมื่อ {local_datetime_text(shift['opened_at'])}\n"
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
            actions.setSpacing(8)
            cancel = QPushButton("ยกเลิก")
            cancel.setObjectName("btnTouchSecondary")
            cancel.setMinimumHeight(52)
            close_old = QPushButton("ปิดกะเดิม")
            close_old.setObjectName("btnTouchDanger")
            close_old.setMinimumHeight(52)
            resume = QPushButton("ทำกะเดิมต่อ")
            resume.setObjectName("btnCheckoutPrimary")
            resume.setMinimumHeight(52)
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

    class PaymentDialog(TouchDialog):
        """หน้าชำระเงิน — เงินทอนคำนวณสด ๆ ระหว่างพิมพ์ ไม่ต้องคิดในหัว"""

        def __init__(self, parent, order: Order):
            super().__init__(parent, "ชำระเงิน", "ตรวจยอดและยืนยันรับชำระ")
            self.order = order
            self.payment_method = "cash"
            self.qr_payload: str | None = None
            self.qr_config = _cached_json_setting(service.db, "qr_payment") or {}
            self.setWindowTitle("รับชำระเงิน")
            self.setWindowFlag(Qt.WindowCloseButtonHint, True)
            self.setMinimumSize(940, 660)
            self.resize(980, 744)
            self.setStyleSheet(self.styleSheet() + f"QLabel {{ font-size:16px; padding:0px; }} QPushButton {{ min-height:56px; font-size:18px; }} QLineEdit {{ min-height:48px; font-size:22px; font-weight:700; }}")
            outer = QHBoxLayout()
            outer.setSpacing(14)
            self.body.addLayout(outer)
            summary_card = QFrame()
            summary_card.setObjectName("card")
            summary = QVBoxLayout(summary_card)
            summary.setContentsMargins(14, 14, 14, 14)
            summary.setSpacing(8)
            outer.addWidget(summary_card, 1)
            layout = QVBoxLayout()
            layout.setSpacing(8)
            outer.addLayout(layout, 1)
            subtotal_label = QLabel(f"ยอดสินค้า  {order.subtotal():,.2f} บาท")
            subtotal_label.setStyleSheet(f"font-size:18px;font-weight:800;color:{PALETTE['text']};")
            summary.addWidget(subtotal_label)
            self.promo_summary = QLabel()
            self.promo_summary.setObjectName("note")
            self.promo_summary.setWordWrap(True)
            self.promo_summary.setMaximumHeight(48)
            summary.addWidget(self.promo_summary)
            disc_label = QLabel("ส่วนลดท้ายบิล (บาท) · แตะเพื่อใช้แป้นตัวเลข")
            disc_label.setStyleSheet(f"font-size:15px;font-weight:700;color:{PALETTE['muted']};")
            summary.addWidget(disc_label)
            self.bill_discount = QLineEdit(str(order.bill_discount))
            self.bill_discount.setObjectName("billDiscount")
            self.bill_discount.setAlignment(Qt.AlignRight)
            self.bill_discount.textChanged.connect(self.discount_changed)
            summary.addWidget(self.bill_discount)
            self.keypad_target = self.amount if hasattr(self, "amount") else self.bill_discount
            summary.addStretch(1)

            due_box = QFrame()
            due_box.setObjectName("totalPayableBox")
            due_layout = QVBoxLayout(due_box)
            due_layout.setContentsMargins(14, 10, 14, 10)
            self.due = QLabel(f"ยอดชำระ {order.grand_total():,.2f} บาท")
            self.due.setObjectName("totalPayableAmount")
            self.due.setStyleSheet(f"font-size:32px;font-weight:900;color:{PALETTE['payable_mint']};")
            due_layout.addWidget(self.due)
            summary.addWidget(due_box)

            methods = QHBoxLayout()
            methods.setSpacing(10)
            self.method_buttons = {}
            method_group = QButtonGroup(self)
            method_group.setExclusive(True)
            for method, label in (("cash", "เงินสด"), ("transfer", "โอน / QR")):
                button = QPushButton(label)
                button.setObjectName("btnPayMethod")
                button.setMinimumHeight(56)
                button.setCheckable(True)
                button.setChecked(method == "cash")
                button.clicked.connect(lambda _, m=method: self.set_payment_method(m))
                method_group.addButton(button)
                self.method_buttons[method] = button
                methods.addWidget(button)
            self.cash_method = self.method_buttons["cash"]
            self.transfer_method = self.method_buttons["transfer"]
            self.body.insertLayout(1, methods)
            self.reference_label = QLabel("เลขอ้างอิงการชำระ / เลขเช็ค")
            self.payment_reference = QLineEdit()
            self.payment_reference.setMaxLength(80)
            summary.addWidget(self.reference_label)
            summary.addWidget(self.payment_reference)
            self.reference_label.hide()
            self.payment_reference.hide()

            self.cash_label = QLabel("เงินที่รับมา")
            self.cash_label.setStyleSheet(f"font-size:16px;font-weight:800;color:{PALETTE['text']};")
            self.amount = QLineEdit(str(order.grand_total()))
            self.amount.setAlignment(Qt.AlignRight)
            self.amount.setStyleSheet(f"min-height:52px;font-size:26px;font-weight:800;color:{PALETTE['brand_navy']};")
            self.amount.textChanged.connect(self.refresh_change)
            layout.addWidget(self.cash_label)
            layout.addWidget(self.amount)

            self.transfer_account_label = QLabel("เลขท้ายบัญชีผู้โอน (4 หลัก)")
            self.transfer_account_last4 = QLineEdit()
            self.transfer_account_last4.setMaxLength(4)
            self.transfer_account_last4.setPlaceholderText("เช่น 4821")
            self.transfer_account_last4.setInputMethodHints(Qt.ImhDigitsOnly)
            self.transfer_account_label.hide()
            self.transfer_account_last4.hide()
            summary.addWidget(self.transfer_account_label)
            summary.addWidget(self.transfer_account_last4)
            self.last4_touch = QPushButton("ใส่เลขท้ายบัญชีด้วยแป้นบนจอ")
            self.last4_touch.setObjectName("btnTouchSecondary")
            self.last4_touch.setMinimumHeight(56)
            self.last4_touch.clicked.connect(self.enter_last4)
            self.last4_touch.hide()
            summary.addWidget(self.last4_touch)

            self.quick_host = QWidget()
            quick = QHBoxLayout(self.quick_host)
            quick.setContentsMargins(0, 0, 0, 0)
            quick.setSpacing(8)
            for note in (100, 500, 1000):
                button = QPushButton(f"{note:,}")
                button.setObjectName("btnCashQuick")
                button.setMinimumHeight(56)
                button.clicked.connect(lambda _, value=note: self.amount.setText(str(value)))
                quick.addWidget(button)
            exact = QPushButton("พอดี")
            exact.setObjectName("btnCashQuick")
            exact.setMinimumHeight(56)
            exact.clicked.connect(lambda: self.amount.setText(str(order.grand_total())))
            quick.addWidget(exact)
            layout.addWidget(self.quick_host)
            self.keypad = NumPad(self.amount)
            layout.addWidget(self.keypad)
            app.focusChanged.connect(self.target_changed)

            change_box = QFrame()
            change_box.setObjectName("dialogNoticeBox")
            change_box.setProperty("tone", "success")
            change_layout = QVBoxLayout(change_box)
            change_layout.setContentsMargins(12, 8, 12, 8)
            self.change = QLabel()
            self.change.setStyleSheet("font-size:24px;font-weight:800;color:%s;" % PALETTE["success_dark"])
            change_layout.addWidget(self.change)
            summary.addWidget(change_box)

            self.qr_box = QWidget()
            qr_layout = QVBoxLayout(self.qr_box)
            self.qr_account = QLabel()
            self.qr_account.setAlignment(Qt.AlignCenter)
            self.qr_account.setWordWrap(True)
            self.qr_image = QLabel()
            self.qr_image.setAlignment(Qt.AlignCenter)
            self.transfer_confirmed = QCheckBox()
            self.transfer_confirmed.setChecked(True)
            qr_layout.addWidget(self.qr_account)
            layout.insertWidget(3, self.qr_image)
            self.qr_image.hide()
            self.transfer_confirmed.hide()
            self.qr_box.hide()
            summary.addWidget(self.qr_box)

            submit = QPushButton("รับเงิน / ออกบิล")
            submit.setObjectName("btnCheckoutPrimary")
            submit.setMinimumHeight(60)
            submit.clicked.connect(self.confirm)
            layout.addWidget(submit)
            self.keypad_target = self.amount
            self.refresh_discount_summary()
            self.refresh_change()

        def hideEvent(self, event):
            try:
                app.focusChanged.disconnect(self.target_changed)
            except (RuntimeError, TypeError):
                pass
            super().hideEvent(event)

        def enter_last4(self):
            value, ok = ValueDialog.get_value(self, "เลขท้ายบัญชี", "ใส่เลขท้ายบัญชีผู้โอน 4 หลัก", self.transfer_account_last4.text())
            if ok:
                self.transfer_account_last4.setText(value)

        def target_changed(self, old, new):
            if new in (self.amount, self.bill_discount, self.transfer_account_last4, self.payment_reference):
                self.keypad_target = new
                self.keypad.target = new

        def keypress(self, key):
            self.keypad.target = self.keypad_target
            self.keypad.press(key)

        def refresh_discount_summary(self):
            gift_names = {p.name or p.promo_type for p in self.order.promotions if p.promo_type in ("free_item", "bill_free_item")}
            names = [f"{name}  −{money(amount):,.2f}" for name, amount in self.order.pricing()[5] if name not in gift_names]
            gifts = [f"แถม {line.name} × {line.qty}  ฿0" for line in self.order.free_items()]
            self.promo_summary.setText(f"ส่วนลดโปร  {self.order.promotion_total():,.2f} บาท\n" + " · ".join(names) + "\n" + "\n".join(gifts))
            self.due.setText(f"ยอดสุทธิ  {self.order.grand_total():,.2f} บาท")
            self.due.setStyleSheet(f"font-size:32px;font-weight:900;color:{PALETTE['payable_mint']};")

        def discount_changed(self):
            try:
                value = Decimal(self.bill_discount.text() or "0")
                if not value.is_finite() or value < 0:
                    return
            except InvalidOperation:
                return
            parent = self.parent()
            if value > 0 and parent is not None and not parent.approve_adjustment():
                return
            self.order.bill_discount = value
            self.refresh_discount_summary()
            if self.payment_method == "transfer":
                self.set_payment_method("transfer")
            else:
                self.amount.setText(str(self.order.grand_total()))
                self.refresh_change()
            if parent is not None:
                parent.refresh_order(keep_selection=True)

        def set_payment_method(self, method: str) -> None:
            self.payment_method = method
            self.method_buttons[method].setChecked(True)
            transfer = method == "transfer"
            cash = method == "cash"
            self.cash_label.setText("เงินที่รับมา")
            for widget in (self.cash_label, self.amount, self.quick_host, self.keypad):
                widget.setVisible(cash)
            for widget in (self.transfer_account_label, self.transfer_account_last4, self.qr_box):
                widget.setVisible(transfer)
            self.keypad.show()
            self.last4_touch.hide()
            self.reference_label.hide()
            self.payment_reference.hide()
            self.transfer_confirmed.setChecked(True)
            self.qr_payload = None
            self.qr_image.hide()
            self.qr_account.setText("ตรวจยอดโอนเข้าบัญชีจากสลิป")
            if method == "transfer" and self.qr_config.get("merchant_ref"):
                try:
                    self.qr_payload = promptpay_payload(str(self.qr_config["merchant_ref"]), self.order.grand_total())
                    self.qr_image.setPixmap(_qr_pixmap(self.qr_payload, 110))
                    self.qr_image.show()
                except (ValueError, RuntimeError) as error:
                    self.qr_payload = None
                    self.qr_account.setText(f"สร้าง QR ไม่ได้: {error} · โอนด้วยบัญชีแล้วตรวจสลิป")
            self.refresh_change()

        def tendered(self) -> Decimal:
            if self.payment_method != "cash":
                return self.order.grand_total()
            try:
                return money(self.amount.text() or "0")
            except (InvalidOperation, ValueError):
                return Decimal("0")

        def refresh_change(self):
            if self.payment_method == "mixed":
                try:
                    cash = money(self.amount.text() or "0")
                    self.change.setText(f"ยอดโอน {self.order.grand_total() - cash:,.2f} บาท")
                except (InvalidOperation, ValueError):
                    self.change.setText("ยอดเงินไม่ถูกต้อง")
                self.transfer_confirmed.setChecked(False)
            elif self.payment_method == "cash":
                paid = self.tendered()
                self.change.setText(f"เงินทอน {paid - self.order.grand_total():,.2f} บาท")
            else:
                self.change.setText(f"รับชำระ {self.order.grand_total():,.2f} บาท")

        def confirm(self):
            from .payments import validate_payment
            try:
                validate_payment(self.payment_method, self.order.grand_total(), self.tendered(),
                                 self.amount.text() or "0", self.transfer_account_last4.text(), True, None)
            except (InvalidOperation, ValueError) as error:
                self.change.setText(str(error))
                return
            self.accept()

    class SettingsDialog(QDialog):
        """ตั้งค่าเฉพาะเครื่องนี้ — ไม่ถูกทับตอน sync แคตตาล็อกจาก ERP"""

        def __init__(self, parent, sample_sale_id: int | None):
            super().__init__(parent)
            self.sample_sale_id = sample_sale_id
            self.setWindowTitle("ตั้งค่า POS")
            self.setObjectName("settingsBackdrop")
            # popup ลอยกลางจอ: พื้นหลังโปร่งแสงทับหน้าขาย การ์ดขอบมนอยู่ตรงกลาง
            self.setWindowFlags(Qt.Dialog | Qt.FramelessWindowHint)
            self.setAttribute(Qt.WA_TranslucentBackground)
            self.setModal(True)
            host = parent.window() if parent is not None else None
            if host is not None:
                self.setGeometry(host.geometry())
            else:
                self.resize(1100, 720)

            backdrop = QVBoxLayout(self)
            backdrop.setContentsMargins(32, 32, 32, 32)
            card = QFrame()
            card.setObjectName("settingsPopup")
            width = min(980, max(720, self.width() - 64))
            height = min(660, max(520, self.height() - 64))
            card.setFixedSize(width, height)
            shadow = QGraphicsDropShadowEffect(card)
            shadow.setBlurRadius(48)
            shadow.setOffset(0, 12)
            shadow.setColor(QColor(15, 23, 42, 110))
            card.setGraphicsEffect(shadow)
            backdrop.addWidget(card, 0, Qt.AlignCenter)

            outer = QVBoxLayout(card)
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
            menu.setObjectName("settingsMenu")
            menu.setLayout(self.pages)
            menu.setFixedWidth(255)
            shell.addWidget(menu)

            self.body = QVBoxLayout()
            self.body.setContentsMargins(20, 18, 20, 18)
            body_host = QWidget()
            body_host.setLayout(self.body)
            body_host.setObjectName("settingsBody")
            shell.addWidget(body_host, 1)

            self.sections = {
                "สถานะเครื่อง": self.status_section,
                "ผูกเครื่องกับ ERP": self.pairing_section,
                "เครื่องพิมพ์และใบเสร็จ": self.printer_section,
                "เครื่องชั่ง / Barcode": self.scale_section,
                "สำรองฐานข้อมูล": self.backup_section,
                "งานพิมพ์ล่าสุด": self.queue_section,
            }
            heading = QLabel("ตั้งค่า POS")
            heading.setObjectName("cardHint")
            heading.setContentsMargins(16, 4, 12, 8)
            self.pages.addWidget(heading)

            group = QButtonGroup(self)
            self.nav_buttons = {}
            for index, name in enumerate(self.sections):
                button = QPushButton(name)
                button.setObjectName("navItem")
                button.setCheckable(True)
                button.setChecked(index == 0)
                button.clicked.connect(lambda _, value=name: self.show_section(value))
                group.addButton(button)
                self.nav_buttons[name] = button
                self.pages.addWidget(button)
            self.pages.addStretch(1)
            note = QLabel("การตั้งค่าอุปกรณ์เป็น local เฉพาะเครื่องนี้\nไม่ถูกทับด้วย sync ERP")
            note.setObjectName("muted")
            note.setContentsMargins(16, 0, 12, 8)
            note.setWordWrap(True)
            self.pages.addWidget(note)

            paired = data_dir is not None and load_device_config(data_dir) is not None
            self.show_section("สถานะเครื่อง" if paired else "ผูกเครื่องกับ ERP")

        def brand_bar(self) -> QWidget:
            bar = QWidget()
            bar.setObjectName("settingsHeader")
            layout = QHBoxLayout(bar)
            layout.setContentsMargins(24, 14, 14, 14)
            titles = QVBoxLayout()
            titles.setSpacing(2)
            name = QLabel("ตั้งค่าเครื่อง POS")
            name.setObjectName("settingsTitle")
            current = self.current_status()
            where = " · ".join(v for v in (current.branch, current.terminal) if v and v.strip("-· "))
            right = QLabel(where or "เครื่องนี้ยังไม่ได้ผูกกับ ERP")
            right.setObjectName("settingsSubtitle")
            titles.addWidget(name)
            titles.addWidget(right)
            mark = QWidget()
            right = QWidget()
            close_button = QToolButton()
            close_button.setObjectName("dialogClose")
            close_button.setText("×")
            close_button.setToolTip("ปิดหน้าต่างตั้งค่า")
            close_button.setAutoRaise(True)
            close_button.clicked.connect(self.reject)
            layout.addLayout(titles)
            layout.addStretch(1)
            layout.addWidget(close_button)
            return bar

        def paintEvent(self, event) -> None:
            # ทำให้หน้าขายด้านหลังมืดลง ให้รู้ว่ากำลังอยู่ในหน้าตั้งค่า
            painter = QPainter(self)
            painter.fillRect(self.rect(), QColor(15, 23, 42, 120))
            painter.end()

        def show_section(self, name: str) -> None:
            if name in getattr(self, "nav_buttons", {}):
                self.nav_buttons[name].setChecked(True)
            while self.body.count():
                item = self.body.takeAt(0)
                widget = item.widget()
                if widget:
                    # ซ่อนและถอดออกทันที ไม่งั้นหัวข้อเก่าค้างซ้อนอยู่จนกว่า deleteLater จะทำงาน
                    widget.hide()
                    widget.setParent(None)
                    widget.deleteLater()

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
            # จอเล็กเลื่อนดูได้ ไม่ให้ปุ่มทับเนื้อหา
            scroll = QScrollArea()
            scroll.setWidgetResizable(True)
            scroll.setFrameShape(QFrame.NoFrame)
            scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
            scroll.setWidget(card)
            self.body.addWidget(scroll, 1)

        def current_status(self) -> "device_status.DeviceStatus":
            config = load_device_config(data_dir) if data_dir is not None else None
            if online is not None:
                connected = bool(getattr(online.worker, "online", online.online))
                error = getattr(online.worker, "last_error", "") or online.error or ""
            else:
                connected, error = False, ""
            return device_status.collect(service.db, server_url=config.server_url if config else "",
                                         online=connected, error=error, paired=config is not None)

        def status_section(self) -> QWidget:
            box = QWidget()
            outer = QVBoxLayout(box)
            outer.setSpacing(12)
            status = self.current_status()

            # แถบสรุปบรรทัดเดียว อ่านจบรู้เลยว่าต้องทำอะไร
            bg, fg = STATUS_COLORS[status.level]
            banner = QLabel(status.headline)
            banner.setWordWrap(True)
            banner.setStyleSheet(f"background:{bg};color:{fg};font-size:16px;font-weight:800;padding:12px 14px;border-radius:7px")
            outer.addWidget(banner)

            # สองคอลัมน์ ให้ทั้งหน้าพอดีจอเครื่องแคชเชียร์โดยไม่ต้องเลื่อน
            identity = QGridLayout()
            identity.setHorizontalSpacing(14)
            identity.setVerticalSpacing(6)
            pairs = [
                ("สาขา", status.branch), ("รหัสเครื่อง", status.terminal),
                ("ERP", status.server_url), ("VAT", f"{service.vat_rate():g}%"),
                ("บิลรอส่ง", f"{status.pending_sales} ใบ"), ("ERP ไม่รับบิล", f"{status.review_sales} ใบ"),
            ]
            for index, (label, value) in enumerate(pairs):
                caption = QLabel(label)
                caption.setObjectName("muted")
                identity.addWidget(caption, index // 2, (index % 2) * 2)
                identity.addWidget(QLabel(value), index // 2, (index % 2) * 2 + 1)
            identity.setColumnStretch(1, 1)
            identity.setColumnStretch(3, 1)
            outer.addLayout(identity)
            if status.error and status.level != "ok":
                detail = QLabel(f"รายละเอียด: {status.error}")
                detail.setWordWrap(True)
                detail.setObjectName("muted")
                outer.addWidget(detail)

            table = QTableWidget(len(status.datasets), 2)
            table.setHorizontalHeaderLabels(["ข้อมูล", "sync ล่าสุด"])
            table.verticalHeader().setVisible(False)
            for row, dataset in enumerate(status.datasets):
                name = QTableWidgetItem(f"{DATASET_ICONS[dataset.state]}  {dataset.label}")
                name.setForeground(QColor(DATASET_COLORS[dataset.state]))
                table.setItem(row, 0, name)
                table.setItem(row, 1, QTableWidgetItem(dataset.detail))
            table.horizontalHeader().setSectionResizeMode(0, QHeaderView.ResizeToContents)
            table.horizontalHeader().setSectionResizeMode(1, QHeaderView.Stretch)
            table.setVerticalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
            table.resizeRowsToContents()
            # สูงพอให้เห็นครบทุกชุดโดยไม่ต้องเลื่อน
            table.setFixedHeight(table.horizontalHeader().sizeHint().height()
                                 + sum(table.rowHeight(r) for r in range(table.rowCount())) + 4)
            outer.addWidget(table)

            review_rows = SyncService(service.db, None).review_sales()
            if review_rows:
                review = QLabel("บิลที่ ERP ไม่รับ:\n" + "\n".join(
                    f"{row['document_no']}: {row['last_error'] or '-'}" for row in review_rows[:5]))
                review.setWordWrap(True)
                review.setStyleSheet("color:#9a5b00;font-weight:600")
                outer.addWidget(review)

            actions = QHBoxLayout()
            sync_now = QPushButton("Sync ตอนนี้")
            sync_now.setObjectName("primary")
            sync_now.clicked.connect(self.test_connection_and_sync)
            actions.addWidget(sync_now)
            if review_rows:
                resend = QPushButton(f"ส่งบิลค้างใหม่ ({len(review_rows)})")
                resend.setToolTip("กดหลังแก้ต้นเหตุบน ERP แล้ว เช่น เปิดกะหรือแก้ราคา")
                resend.clicked.connect(self.resend_review_sales)
                actions.addWidget(resend)
            logs = QPushButton("ดูบันทึก")
            logs.clicked.connect(self.show_sync_logs)
            actions.addWidget(logs)
            actions.addStretch(1)
            outer.addLayout(actions)
            outer.addStretch(1)
            return box

        def pairing_section(self) -> QWidget:
            box = QWidget()
            outer = QVBoxLayout(box)
            if data_dir is None:
                outer.addWidget(QLabel("ไม่พบโฟลเดอร์ข้อมูล POS"))
                return box
            steps = QLabel(
                "1. ที่ ERP: POS / หน้าร้าน → จัดการเครื่อง POS → กด เพิ่มเครื่อง ที่สาขานี้\n"
                "2. คัดลอก Token ที่ขึ้นบนสุดของหน้า\n"
                "3. วางด้านล่าง แล้วกด ทดสอบและบันทึก"
            )
            steps.setWordWrap(True)
            outer.addWidget(steps)
            form = QFormLayout()
            current = load_device_config(data_dir)
            self.server_url = QLineEdit(current.server_url if current else "https://erp.popstarcenter.com")
            self.server_url.setPlaceholderText("https://erp.popstarcenter.com")
            self.device_token = QLineEdit()
            self.device_token.setPlaceholderText("วาง Token ที่คัดลอกจาก ERP")
            self.device_token.setEchoMode(QLineEdit.Password)
            show = QCheckBox("แสดง Token")
            show.toggled.connect(lambda on: self.device_token.setEchoMode(QLineEdit.Normal if on else QLineEdit.Password))
            form.addRow("ที่อยู่ ERP", self.server_url)
            form.addRow("Token", self.device_token)
            form.addRow("", show)
            paste = QPushButton("วาง Token")
            paste.clicked.connect(lambda: (self.device_token.clear(), self.device_token.setText(app.clipboard().text().strip())))
            form.addRow("", paste)
            outer.addLayout(form)
            save = QPushButton("ทดสอบและบันทึก")
            save.setObjectName("primary")
            save.clicked.connect(self.save_pairing)
            outer.addWidget(save)
            note = QLabel("เปลี่ยน Token เมื่อ ERP ออก Token ใหม่ให้เครื่องนี้ · ไม่ต้องรีเซ็ต PIN พนักงาน")
            note.setObjectName("muted")
            note.setWordWrap(True)
            outer.addWidget(note)
            outer.addStretch(1)
            return box

        def printer_section(self) -> QWidget:
            box = QWidget()
            outer = QHBoxLayout(box)

            left = QWidget()
            form = QFormLayout(left)
            self.profile_name = QLineEdit(f"เคาน์เตอร์ {self.current_status().terminal}")
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
            for field in (self.profile_name, self.driver, self.windows_printer, self.connection, self.address, self.paper, self.drawer):
                field.setMinimumWidth(160)
            self.windows_printer.setSizeAdjustPolicy(QComboBox.AdjustToMinimumContentsLengthWithIcon)
            self.windows_printer.setMinimumContentsLength(14)
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
            right.setMinimumWidth(0)
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
            # ห่อบรรทัดได้ ไม่งั้นข้อความยาวดันหน้าต่างกว้างจนตัวอย่างล้นขอบ
            self.preview.setWordWrap(True)
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
                TouchMessage.warning(self, "ยังไม่ได้เลือกเครื่องพิมพ์", "เลือก printer ที่ Windows ติดตั้งก่อนทดสอบพิมพ์")
                return
            text = receipt_for(service.db, self.sample_sale_id) if self.sample_sale_id else "JET POS\nทดสอบเครื่องพิมพ์\n"
            try:
                print_text_to_windows_queue(
                    text, printer_name, paper_width_mm=active_paper_width(service.db)
                )
            except Exception as error:
                TouchMessage.critical(self, "ส่งงานพิมพ์ไม่สำเร็จ", str(error))
                return
            TouchMessage.information(self, "ทดสอบพิมพ์", f"ส่งใบเสร็จตัวอย่างไปที่ {printer_name} แล้ว")

        def save_printer(self) -> None:
            drivers = ["EPSON_ESC_POS", "STAR", "GENERIC_ESC_POS", "MOCK"]
            profile_name = self.profile_name.text().strip()
            if not profile_name:
                TouchMessage.warning(self, "บันทึกไม่ได้", "กรุณาระบุชื่อโปรไฟล์เครื่องพิมพ์")
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
                SettingsService(service.db).set_device_setting(
                    "cash_drawer_enabled", "1" if self.drawer.currentIndex() == 0 else "0"
                )
            except Exception as error:
                TouchMessage.critical(self, "บันทึกไม่ได้", str(error))
                return
            TouchMessage.information(self, "บันทึกแล้ว", f"บันทึกโปรไฟล์ {profile_name} แล้ว")

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

        def resend_review_sales(self) -> None:
            count = SyncService(service.db, None).requeue_review_sales()
            if online is not None and hasattr(online.worker, "wake"):
                online.worker.wake()
            TouchMessage.information(self, "ส่งบิลใหม่", f"นำบิล {count} ใบกลับเข้าคิว ระบบจะส่งขึ้น ERP ในรอบถัดไป")

        def test_connection_and_sync(self) -> None:
            if online is None:
                TouchMessage.warning(self, "ยังไม่ผูก ERP", "ไปที่ ผูกเครื่องกับ ERP แล้ววาง Token ก่อน")
                return
            try:
                profile = online.provisioning.ping()
                branch_id = int(profile.get("branch_id") or online.branch_id or 0)
                result = online.provisioning.pull_cashiers(branch_id)
                online.online = True
                online.worker.online = True
                online.worker.last_error = ""
                # ให้ worker ดึงสินค้า/ราคาใหม่ทั้งชุดแล้วส่งคิวที่ค้างในรอบถัดไปทันที
                online.worker.needs_down_sync = True
                online.worker.wake()
                TouchMessage.information(
                    self,
                    "เชื่อม ERP ได้",
                    f"สาขา: {profile.get('branch_name', '-')}\n"
                    f"อัปเดตคนขาย {result['upserted']} คน\n"
                    "กำลังดึงสินค้า/ราคา และส่งบิลค้างในเบื้องหลัง",
                )
                self.show_section("สถานะเครื่อง")
            except Exception as error:
                online.online = False
                online.worker.online = False
                online.worker.last_error = str(error)
                TouchMessage.warning(self, "เชื่อม ERP ไม่ได้", f"{error}\n\nตรวจเน็ต หรือไปที่ ผูกเครื่องกับ ERP เพื่อวาง Token ใหม่")
                self.show_section("สถานะเครื่อง")

        def show_sync_logs(self) -> None:
            rows = service.db.execute(
                "SELECT direction, status, message, created_at FROM sync_logs ORDER BY id DESC LIMIT 20"
            ).fetchall()
            auth_pending = service.db.execute("SELECT count(*) FROM auth_events_outbox WHERE synced = 0").fetchone()[0]
            text = "\n".join(f"{local_datetime_text(row['created_at'])} · {row['direction']} · {row['status']} · {row['message'] or '-'}" for row in rows)
            TouchMessage.information(self, "บันทึกการเชื่อมต่อ", f"Audit รอส่ง: {auth_pending}\n\n{text or 'ยังไม่มีบันทึก'}")

        def save_pairing(self) -> None:
            url = normalize_server_url(self.server_url.text())
            token = self.device_token.text().strip()
            if not url or not token:
                TouchMessage.warning(self, "ข้อมูลไม่ครบ", "กรอกที่อยู่ ERP และ device token ให้ครบ")
                return
            insecure = url.startswith("http://")  # ยอม http เฉพาะ IP/แลบที่ผู้ใช้ตั้งใจ
            # ทดสอบกับ ERP ก่อนบันทึก — Token ผิดจะไม่ทับค่าที่ใช้งานได้อยู่
            try:
                profile = LaravelPosClient(url, token, allow_insecure=insecure).get("/api/pos/ping")
                if not profile.get("success", False):
                    raise RuntimeError(profile.get("message", "ERP ไม่ยืนยันเครื่องนี้"))
            except Exception as error:
                TouchMessage.warning(self, "เชื่อม ERP ไม่ได้", f"{error}\n\nตรวจว่า Token คัดลอกครบ และที่อยู่ ERP ถูกต้อง")
                return
            try:
                save_device_config(data_dir, DeviceConfig(server_url=url, device_token=token, allow_insecure=insecure))
            except Exception as error:
                TouchMessage.critical(self, "บันทึกไม่สำเร็จ", str(error))
                return
            TouchMessage.information(
                self, "ผูกเครื่องแล้ว",
                f"สาขา: {profile.get('branch_name', '-')}\nเครื่อง: {(profile.get('device') or {}).get('terminal_code', '-')}\n\n"
                "ปิดและเปิด POS ใหม่เพื่อ sync ข้อมูลของสาขานี้",
            )
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
                TouchMessage.warning(self, "สำรองไม่ได้", "ไม่พบโฟลเดอร์ข้อมูล POS")
                return
            backup_dir = data_dir / "backups"
            backup_dir.mkdir(parents=True, exist_ok=True)
            path = backup_dir / f"pos-{datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')}.sqlite"
            try:
                target = sqlite3.connect(path)
                service.db.backup(target)
                target.close()
            except Exception as error:
                TouchMessage.warning(self, "สำรองไม่สำเร็จ", str(error))
                return
            TouchMessage.information(self, "สำรองแล้ว", f"บันทึกฐานข้อมูลไว้ที่\n{path}")

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

    class CloseShiftDialog(TouchDialog):
        def __init__(self, parent, expected):
            super().__init__(parent, "ปิดกะขาย", "ปิดกะ: นับเงินในลิ้นชักทั้งหมด แล้วส่งบิลค้างให้ครบ")
            self.setMinimumWidth(480)
            self.body.setContentsMargins(10, 10, 10, 10)
            self.body.setSpacing(6)
            self.expected = expected
            self.expected_label = QLabel()
            self.expected_label.setObjectName("note")
            self.body.addWidget(self.expected_label)
            self.body.addWidget(QLabel("เงินสดที่นับได้จริง"))
            self.amount = QLineEdit()
            self.amount.setObjectName("shiftAmount")
            self.amount.setAlignment(Qt.AlignRight)
            self.body.addWidget(self.amount)
            self.difference = QLabel()
            self.difference.setObjectName("shiftSummary")
            self.difference.setAlignment(Qt.AlignCenter)
            self.body.addWidget(self.difference)
            self.note = QLineEdit()
            self.note.setPlaceholderText("หมายเหตุ")
            self.note.setStyleSheet("min-height:38px; font-size:15px;")
            self.body.addWidget(self.note)
            self.pad = NumPad(self.amount)
            self.body.addWidget(self.pad)
            self.submit = self.confirm("ปิดกะขาย")
            self.submit.setObjectName("btnCheckoutPrimary")
            self.submit.setMinimumHeight(52)
            self.amount.textChanged.connect(self.refresh)
            self.refresh()
            self.amount.selectAll()

        def accept(self):
            self.refresh()
            if self.submit.isEnabled():
                super().accept()

        def refresh(self):
            self.expected_label.setText("นับเงินสดจริงก่อนยืนยันปิดกะ")
            try:
                counted = money(self.amount.text())
                delta = counted - self.expected
                self.difference.setText(f"{'เกิน' if delta > 0 else 'ขาด' if delta < 0 else 'พอดี'} {abs(delta):,.2f} บาท")
                self.difference.setStyleSheet(f"color:{PALETTE['danger'] if delta < 0 else PALETTE['success']}")
                valid = counted.is_finite() and counted >= 0
            except (InvalidOperation, ValueError):
                valid = False
            pending = service.db.execute("SELECT count(*) FROM sales WHERE shift_id = ? AND sync_status != 'synced'", (self.parent().shift_id,)).fetchone()[0]
            self.submit.setEnabled(valid and not pending)
            if pending:
                self.difference.setText(f"ต้องส่งบิลค้าง {pending} ใบขึ้น ERP ก่อนปิดกะ")

    class FloatCloseDialog(TouchDialog):
        """One full-window workspace; all monetary previews remain server authoritative."""
        def __init__(self, parent, protocol, policy):
            super().__init__(parent, "ปิดกะขาย", "ปิดกะ: นับเงินในลิ้นชักทั้งหมด → ตรวจยอด → แยกเงิน → ยืนยันและพิมพ์ใบปิดกะ")
            self.setFixedSize(1024, 768)
            self.body.setContentsMargins(12, 8, 12, 8)
            self.body.setSpacing(6)
            self.protocol = protocol
            self.kept_edited = False
            self.amount = QLineEdit()
            self.amount.setAlignment(Qt.AlignRight)
            self.amount.installEventFilter(self)
            self.kept = QLineEdit(str(cash(policy['default_float'])))
            self.kept.setAlignment(Qt.AlignRight)
            self.reference = QLineEdit()
            self.reference.setMaxLength(80)
            self.note = QLineEdit()
            self.note.setMaxLength(500)
            self.fields = [self.amount, self.kept, self.reference, self.note]
            stepper_row = QHBoxLayout()
            stepper_row.setSpacing(8)
            self.step_pills = []
            for step_title in ("1. นับเงินสด", "2. ตรวจยอดขาด/เกิน", "3. แยกเงินทอน/นำส่ง", "4. ยืนยันและพิมพ์ Z 2 ใบ"):
                pill = QLabel(step_title)
                pill.setObjectName("stepPill")
                pill.setAlignment(Qt.AlignCenter)
                pill.setFixedHeight(32)
                self.step_pills.append(pill)
                stepper_row.addWidget(pill, 1)
            self.body.addLayout(stepper_row)
            self.steps = QStackedWidget()
            self.body.addWidget(self.steps, 1)
            for _ in range(4):
                page = QWidget()
                page_layout = QVBoxLayout()
                page_layout.setContentsMargins(4, 4, 4, 4)
                page_layout.setSpacing(6)
                page.setLayout(page_layout)
                self.steps.addWidget(page)
            count = self.steps.widget(0).layout()
            columns = QHBoxLayout()
            columns.setSpacing(12)
            count.addLayout(columns, 1)
            self.coins = QTableWidget(11, 3)
            self.coins.setHorizontalHeaderLabels(['ชนิด (บาท)', 'จำนวน', 'ยอด'])
            self.coins.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
            self.coins.verticalHeader().hide()
            self.coins.setVerticalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
            self.coin_fields = []
            for row, value in enumerate(('1000','500','100','50','20','10','5','2','1','0.50','0.25')):
                self.coins.setItem(row, 0, QTableWidgetItem(value))
                self.coins.item(row, 0).setFlags(Qt.ItemIsEnabled)
                entry = QLineEdit('0')
                entry.setStyleSheet('min-height:26px; font-size:17px;')
                entry.installEventFilter(self)
                self.coin_fields.append(entry)
                self.coins.setCellWidget(row, 1, entry)
                self.coins.setRowHeight(row, 34)
                entry.textChanged.connect(self.denominations)
            columns.addWidget(self.coins, 3)
            right = QVBoxLayout()
            right.setSpacing(6)
            columns.addLayout(right, 2)
            right.addWidget(QLabel('ยอดนับจริง / แตะจำนวนใบหรือเหรียญ'))
            right.addWidget(self.amount)
            self.pad = NumPad(self.amount)
            right.addWidget(self.pad)
            right.addStretch()
            self.total = QLabel('รวม — บาท')
            self.total.setStyleSheet('font-size:28px; font-weight:bold;')
            count.addWidget(self.total)
            self.error = QLabel('')
            self.error.setWordWrap(True)
            self.body.addWidget(self.error)
            self.result_label = QLabel('ยังไม่ได้ตรวจยอด')
            self.result_label.setWordWrap(True)
            review = self.steps.widget(1).layout()
            review.addWidget(self.result_label)
            self.drops = QLabel()
            self.drops.setWordWrap(True)
            review.addWidget(self.drops)
            self.drop_rows = service.db.execute(
                "SELECT amount, reference_no, reason FROM cash_movements WHERE shift_id=? AND movement_type='drop' ORDER BY id",
                (parent.shift_id,)).fetchall()
            self.drop_page = 0
            self.drop_list = QLabel()
            self.drop_list.setWordWrap(True)
            review.addWidget(self.drop_list)
            drop_nav = QHBoxLayout()
            review.addLayout(drop_nav)
            for caption, delta in [('รายการก่อนหน้า', -1), ('รายการถัดไป', 1)]:
                button = QPushButton(caption)
                button.setObjectName("btnTouchSecondary")
                button.clicked.connect(lambda checked=False, delta=delta: self.show_drops(delta))
                drop_nav.addWidget(button)
            self.show_drops(0)
            review.addStretch()
            split_page = self.steps.widget(2).layout()
            split_columns = QHBoxLayout()
            split_page.addLayout(split_columns, 1)
            split_host = QWidget()
            split = QVBoxLayout(split_host)
            split_columns.addWidget(split_host, 1)
            split.addWidget(QLabel('เงินทอนเก็บในลิ้นชัก (ไม่เกินยอดนับ)'))
            split.addWidget(self.kept)
            self.keep_pad = NumPad(self.kept)
            split_columns.addWidget(self.keep_pad, 1)
            split.addWidget(QLabel('เลขซองเงินนำส่งปิดกะ / หมายเหตุ'))
            split.addWidget(self.reference)
            split.addWidget(self.note)
            self.deposit = QLabel('เงินนำส่งปิดกะ: รอตรวจยอดจาก ERP')
            self.deposit.setStyleSheet('font-size:26px; font-weight:bold;')
            split_page.addWidget(self.deposit)
            self.summary = QLabel()
            self.summary.setWordWrap(True)
            self.steps.widget(3).layout().addWidget(self.summary)
            self.steps.widget(3).layout().addStretch()
            actions = QHBoxLayout()
            actions.setSpacing(10)
            self.body.addLayout(actions)
            self.back = QPushButton('ย้อนกลับ')
            self.back.setObjectName("btnTouchSecondary")
            self.back.clicked.connect(self.previous)
            actions.addWidget(self.back)
            self.next = QPushButton('ถัดไป')
            self.next.setObjectName("btnTouchPrimary")
            self.next.clicked.connect(self.advance)
            actions.addWidget(self.next)
            self.submit = QPushButton('ยืนยันปิดกะและพิมพ์ใบปิดกะ')
            self.submit.setObjectName("btnCheckoutPrimary")
            self.submit.clicked.connect(self.accept)
            actions.addWidget(self.submit)
            for field in self.fields:
                field.textChanged.connect(self.invalidate)
            self.amount.textChanged.connect(self.default_kept)
            self.amount.textChanged.connect(lambda: self.total.setText('รวม ' + self.amount.text() + ' บาท'))
            self.kept.textEdited.connect(lambda: setattr(self, 'kept_edited', True))
            self.invalidate()
            self.navigate(0)

        def show_drops(self, delta):
            self.drop_page = max(0, min(self.drop_page + delta, max(0, (len(self.drop_rows) - 1) // 4)))
            rows = self.drop_rows[self.drop_page * 4:self.drop_page * 4 + 4]
            self.drop_list.setText('\n'.join(
                f"{cash(row['amount']):,.2f} บาท · ซอง {row['reference_no'] or '-'} · {row['reason'][:70]}"
                for row in rows) or 'ไม่มีรายการนำส่งระหว่างกะในเครื่อง')

        def eventFilter(self, watched, event):
            if event.type() == QEvent.FocusIn and (watched is self.amount or watched in getattr(self, 'coin_fields', [])):
                self.set_pad_target(watched)
            return super().eventFilter(watched, event)

        def set_pad_target(self, field):
            self.pad.target = field
            for entry in self.coin_fields:
                entry.setStyleSheet('min-height:28px; font-size:18px;' + ('background:#dbeafe;' if entry is field else ''))
            field.selectAll()

        def default_kept(self):
            if not self.kept_edited:
                try:
                    self.kept.setText(str(min(cash(self.amount.text()), cash(self.protocol.policy['default_float']))))
                except ValueError:
                    pass

        def invalidate(self, *_):
            self.protocol.invalidate()
            self.result_label.setText('ยอดเปลี่ยนแล้ว ต้องตรวจยอดใหม่')
            self.deposit.setText('เงินนำส่งปิดกะ: รอตรวจยอดจาก ERP')
            self.summary.clear()
            self.submit.setEnabled(False)

        def denominations(self, *_):
            try:
                total = Decimal('0')
                for row, entry in enumerate(self.coin_fields):
                    value = cash(entry.text() or '0')
                    if value != value.to_integral_value():
                        raise ValueError('จำนวนใบ/เหรียญต้องเป็นจำนวนเต็ม')
                    subtotal = value * Decimal(self.coins.item(row, 0).text())
                    self.coins.setItem(row, 2, QTableWidgetItem(f'{subtotal:,.2f}'))
                    self.coins.item(row, 2).setFlags(Qt.ItemIsEnabled)
                    total += subtotal
                self.error.clear()
                self.amount.setText(str(total))
                self.next.setEnabled(True)
            except ValueError as error:
                self.invalidate()
                self.error.setText(cashier_error(error))
                self.next.setEnabled(False)

        def inspect(self):
            try:
                shift = self.protocol.inspect(*(field.text() for field in self.fields))
                delta = cash(shift['cash_difference'], signed=True)
                self.result_label.setText(
                    f"ควรมี {cash(shift['expected_cash'], signed=True):,.2f} · นับได้ {cash(shift['counted_cash']):,.2f}\n"
                    f"ขาด/เกิน {delta:,.2f}\nเงินทอนเก็บ {cash(shift['float_kept']):,.2f}\n"
                    f"เงินนำส่งปิดกะ {cash(shift['deposit_amount']):,.2f} · ซอง {self.reference.text()}")
                self.result_label.setStyleSheet(f"font-size:26px; color:{PALETTE['danger'] if delta < 0 else PALETTE['success']}")
                self.drops.setText('นำส่งเงินระหว่างกะที่บันทึกแล้ว: ' + str(shift.get('cash_drops', 'ไม่มีข้อมูล')))
                self.deposit.setText(f"เงินนำส่งปิดกะ {cash(shift['deposit_amount']):,.2f} บาท" +
                    (' · เงินทอนต่ำกว่าค่าเริ่มต้น' if cash(shift['float_kept']) < cash(self.protocol.policy['default_float']) else ''))
                self.summary.setText(self.result_label.text() + '\n' + self.drops.text() + '\nหมายเหตุ: ' + self.note.text())
                self.submit.setEnabled(True)
                self.error.clear()
                return True
            except Exception as error:
                self.invalidate()
                self.error.setText(cashier_error(error))
                return False

        def navigate(self, index):
            self.steps.setCurrentIndex(index)
            for idx, pill in enumerate(getattr(self, "step_pills", [])):
                pill.setProperty("active", "true" if idx == index else "false")
                pill.style().unpolish(pill)
                pill.style().polish(pill)
            self.back.setText('กลับไปขาย' if index == 0 else 'ย้อนกลับ')
            self.next.setVisible(index < 3)
            self.submit.setVisible(index == 3)

        def previous(self):
            index = self.steps.currentIndex()
            if index == 0:
                self.reject()
            else:
                self.navigate(index - 1)

        def advance(self):
            index = self.steps.currentIndex()
            if index in (0, 2) and not self.inspect():
                return
            self.navigate(min(index + 1, 3))

        def accept(self):
            if self.steps.currentIndex() == 3 and self.protocol.preview is not None and self.submit.isEnabled():
                super().accept()

    class AdminAuthDialog(TouchDialog):
        def __init__(self, parent):
            super().__init__(parent)
            self.setWindowTitle("ยืนยันผู้ดูแลก่อนตั้งค่า POS")
            self.setMinimumWidth(390)
            layout = self.body
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
                TouchMessage.warning(self, "ยืนยันไม่สำเร็จ", cashier_error(error))
                return
            self.accept()

    class PosWindow(QMainWindow):
        def __init__(self, cashier=None):
            super().__init__()
            self.cashier = None
            self.locked = True
            self._allow_close = False
            self.setWindowIcon(app_icon())
            saved = SettingsService(service.db).get_device_setting("current_order", None)
            self.order = Order.restore(saved) if saved else Order()
            self.category = ALL_CATEGORIES
            self.shift_id: int | None = None
            self.opening_cash: Decimal | None = None
            self.last_sale_id: int | None = None
            self._product_columns: int | None = None
            self.layout_runtime = layout_runtime
            layout_version = layout_config.get("layout_version", layout_config.get("version", 1))
            self.setWindowTitle(f"JET POS v{APP_VERSION} — พร้อมใช้งาน · Layout {layout_version}")
            self.setStyleSheet(runtime_style)
            self.settings_shortcut = QShortcut(QKeySequence("Ctrl+Alt+S"), self)
            self.settings_shortcut.activated.connect(self.open_settings)

            root = QWidget()
            shell = QVBoxLayout(root)
            shell.setContentsMargins(0, 0, 0, 0)
            shell.setSpacing(0)
            shell.addWidget(self.build_runtime_topbar())
            tool_bar = QWidget()
            tool_bar.setObjectName("jetToolStrip")
            tools = QHBoxLayout(tool_bar)
            tools.setContentsMargins(12, 4, 12, 4)
            tools.setSpacing(8)
            for label, action in [("ประวัติบิล / คืนสินค้า", self.show_history), ("นำส่งเงินระหว่างกะ", self.record_cash_movement), ("พิมพ์ใบเสร็จล่าสุด", self.show_last_receipt)]:
                btn = self._top_button(label, label, action)
                btn.setMinimumHeight(48)
                tools.addWidget(btn)
            tools.addStretch(1)
            shell.addWidget(tool_bar)
            columns = QHBoxLayout()
            columns.setContentsMargins(0, 0, 0, 0)
            columns.setSpacing(0)
            order_panel = self.build_order_panel()
            product_panel = self.build_product_panel()
            # The web designer controls pane order, while the splitter keeps both
            # panes usable on 14-15 inch POS screens instead of squeezing buttons.
            # แบบ JET POS: สินค้าซ้าย บิลขวา ลำดับและสัดส่วนยังตามที่ออกแบบไว้ใน ERP
            # บิลกว้างไม่เกิน 520px จอ 1024 จะได้เหลือที่ให้การ์ดสินค้า
            order_x = self._layout_x("cart", 8)
            product_x = self._layout_x("product_grid", 1)
            splitter = QSplitter(Qt.Horizontal)
            splitter.setObjectName("primarySplitter")
            splitter.setChildrenCollapsible(False)
            splitter.setHandleWidth(1)
            if order_x < product_x:
                first, second = order_panel, product_panel
            else:
                first, second = product_panel, order_panel
            first.setMinimumWidth(360)
            second.setMinimumWidth(360)
            order_panel.setMaximumWidth(520)
            self.order_panel = order_panel
            splitter.addWidget(first)
            splitter.addWidget(second)
            first_is_product = first is product_panel
            first_ratio = layout_runtime["product_width"] if first_is_product else layout_runtime["cart_width"]
            second_ratio = layout_runtime["cart_width"] if first_is_product else layout_runtime["product_width"]
            splitter.setStretchFactor(0, first_ratio)
            splitter.setStretchFactor(1, second_ratio)
            splitter.setSizes([first_ratio * 10, second_ratio * 10])
            self.primary_splitter = splitter
            columns.addWidget(splitter)
            shell.addLayout(columns, 1)
            self.pages = QStackedWidget()
            self.pages.addWidget(root)
            self.lock_page = QWidget()
            self.lock_page.setObjectName("sessionLock")
            lock_outer = QVBoxLayout(self.lock_page)
            lock_outer.setContentsMargins(180, 48, 180, 48)
            lock_outer.addStretch()
            lock_card = QFrame()
            lock_card.setObjectName("lockCard")
            lock = QVBoxLayout(lock_card)
            lock.setContentsMargins(40, 32, 40, 32)
            lock.setSpacing(14)
            lock.addWidget(logo_label(height=100), alignment=Qt.AlignHCenter)
            title = QLabel("JET POS")
            title.setAlignment(Qt.AlignCenter)
            title.setStyleSheet(f"font-size:36px; font-weight:900; color:{PALETTE['brand_navy']};")
            lock.addWidget(title)
            self.lock_status = QLabel("พร้อมเข้างาน · กะและตะกร้าเก็บอยู่ในเครื่อง")
            self.lock_status.setObjectName("note")
            self.lock_status.setAlignment(Qt.AlignCenter)
            lock.addWidget(self.lock_status)
            self.start_button = QPushButton("เข้างาน")
            self.start_button.setObjectName("btnCheckoutPrimary")
            self.start_button.setMinimumHeight(76)
            self.start_button.setStyleSheet(f"background:{PALETTE['success']}; color:{PALETTE['surface']}; font-size:26px; font-weight:800; border-radius:12px;")
            self.start_button.clicked.connect(self.start_work)
            lock.addWidget(self.start_button)
            lock_actions = QHBoxLayout()
            lock_actions.setSpacing(12)
            settings = QPushButton("⚙ ตั้งค่าเครื่อง")
            settings.setObjectName("btnTouchSecondary")
            settings.setMinimumHeight(56)
            settings.clicked.connect(self.open_settings)
            lock_actions.addWidget(settings)
            exit_button = QPushButton("⏻ ปิดโปรแกรม")
            exit_button.setObjectName("btnTouchDanger")
            exit_button.setMinimumHeight(56)
            exit_button.clicked.connect(self.close)
            lock_actions.addWidget(exit_button)
            lock.addLayout(lock_actions)
            lock_outer.addWidget(lock_card)
            lock_outer.addStretch()
            self.pages.addWidget(self.lock_page)
            self.pages.setCurrentWidget(self.lock_page)
            self.setCentralWidget(self.pages)

            self.pay_shortcut.setEnabled(False)
            self.refresh_products()
            self.refresh_order()
            # ราคาที่มีผลตามเวลาอาจเปลี่ยนระหว่างเปิดโปรแกรม จึงวาดปุ่มสินค้า
            # ใหม่เป็นระยะโดยใช้ clock ที่ sync จาก ERP ไม่ต้อง build รุ่นใหม่
            self.price_timer = QTimer(self)
            self.price_timer.timeout.connect(self.refresh_products)
            self.price_timer.start(30000)
            self.cash_warning_timer = QTimer(self)
            self.cash_warning_timer.timeout.connect(self.refresh_cash_warning)
            self.cash_warning_timer.start(30000)

        def resizeEvent(self, event) -> None:
            """Keep the working area usable on common 14-15 inch POS screens."""
            super().resizeEvent(event)
            # จอ 1024 บิลกว้าง ~440 จอใหญ่ขยายได้ถึง 520 การ์ดสินค้าจะได้ไม่ตัดชื่อ
            if getattr(self, "order_panel", None) is not None:
                self.order_panel.setMaximumWidth(max(400, min(520, int(self.width() * 0.43))))
            compact = self.width() <= 1450 or self.height() <= 900
            mode_changed = self.property("compact") != compact
            if mode_changed:
                self.setProperty("compact", compact)
                self.style().unpolish(self)
                self.style().polish(self)

            columns = self._product_columns_for_width()
            viewport = getattr(self, "product_scroll", None)
            view_size = (
                viewport.viewport().width(), viewport.viewport().height()
            ) if viewport is not None else None
            size_changed = getattr(self, "_product_view_size", None) != view_size
            if mode_changed or columns != self._product_columns or size_changed:
                self._product_view_size = view_size
                self.refresh_products()

        def build_runtime_topbar(self) -> QWidget:
            """แถบบนแบบ JET POS: ชื่อร้าน · สาขา/เครื่อง · กะ · ปุ่มลัดด้านขวา"""
            bar = QWidget()
            bar.setObjectName("jetTopbar")
            bar.setFixedHeight(64)
            layout = QHBoxLayout(bar)
            layout.setContentsMargins(14, 6, 12, 6)
            layout.setSpacing(8)

            layout.addWidget(logo_label(height=44))
            brand = QVBoxLayout()
            brand.setSpacing(0)
            name = QLabel("JET POS")
            name.setObjectName("jetBrand")
            tagline = QLabel("MPM FOOD GROUP")
            tagline.setObjectName("jetTagline")
            brand.addWidget(name)
            brand.addWidget(tagline)
            layout.addLayout(brand)

            branch_name = (online.profile.get("branch_name") if online is not None else None) or service._setting("branch_name") or f"สาขา {branch_id}"
            self.runtime_context = {}
            for key, caption, value, detail in [
                ("show_branch", "สาขา / เครื่อง", branch_name, terminal_id),
                ("show_shift", "กะขาย", "ยังไม่เปิดกะ", "ยังไม่เลือกคนขาย"),
            ]:
                block = QWidget()
                block.setObjectName("jetContext")
                block_layout = QVBoxLayout(block)
                block_layout.setContentsMargins(12, 0, 12, 0)
                block_layout.setSpacing(0)
                head = QLabel(caption)
                head.setObjectName("jetContextCaption")
                main = QLabel(f"{value}  ·  {detail}")
                main.setObjectName("jetContextValue")
                block_layout.addWidget(head)
                block_layout.addWidget(main)
                block.setVisible(bool(self.layout_runtime.get(key, True)))
                layout.addWidget(block)
                self.runtime_context[key] = main

            layout.addStretch(1)

            self.connection_chip = QLabel("ออฟไลน์")
            self.connection_chip.setObjectName("jetStatus")
            layout.addWidget(self.connection_chip)

            self.auth_button = self._top_button("🔒 ล็อกหน้าจอ", "พักหน้าจอชั่วคราวโดยเก็บกะและตะกร้า", self.lock_session)
            layout.addWidget(self.auth_button)
            layout.addWidget(self._top_button("ปิดกะ", "นับเงิน ปิดกะ และพิมพ์ Z", self.close_current_shift))
            layout.addWidget(self._top_button("⏻ ปิดโปรแกรม", "เก็บตะกร้าก่อนปิด", self.close))
            self.settings_button = self._top_button("⚙ ตั้งค่า", "ตั้งค่าเครื่อง POS", self.open_settings)
            layout.addWidget(self.settings_button)
            return bar

        def _top_button(self, label: str, tip: str, action) -> QPushButton:
            button = QPushButton(label)
            button.setObjectName("jetTopButton")
            button.setToolTip(tip)
            button.setMinimumHeight(48)
            button.setCursor(Qt.PointingHandCursor)
            button.clicked.connect(action)
            return button

        def refresh_runtime_context(self) -> None:
            if not getattr(self, "runtime_context", None):
                return
            cashier_name = self.cashier["name"] if self.cashier is not None else "ยังไม่เลือกคนขาย"
            shift_text = f"กะ #{self.shift_id}" if self.shift_id is not None else "ยังไม่เปิดกะ"
            self.runtime_context["show_shift"].setText(f"{shift_text}\n{cashier_name}")
            if getattr(self, "auth_button", None) is not None:
                self.auth_button.setText("🔒 ล็อกหน้าจอ")
            if getattr(self, "connection_chip", None) is not None:
                connected = online is not None and bool(getattr(online.worker, "online", online.online))
                self.connection_chip.setText("● ออนไลน์" if connected else "● ออฟไลน์")
                self.connection_chip.setProperty("online", "true" if connected else "false")
                self.connection_chip.style().unpolish(self.connection_chip)
                self.connection_chip.style().polish(self.connection_chip)

        # ---------- พื้นที่บิล ----------

        def build_order_panel(self) -> QWidget:
            panel = QWidget()
            panel.setObjectName("cartPanel")
            layout = QVBoxLayout(panel)
            layout.setContentsMargins(0, 0, 0, 0)
            layout.setSpacing(0)

            self.cash_warning_label = QLabel("")
            self.cash_warning_label.setWordWrap(True)
            self.cash_warning_label.setStyleSheet("color:#b45309;font-weight:bold")
            layout.addWidget(self.cash_warning_label)
            head = QWidget()
            head.setObjectName("cartTitle")
            head.setFixedHeight(56)
            head_layout = QHBoxLayout(head)
            head_layout.setContentsMargins(14, 4, 10, 4)
            head_layout.setSpacing(6)
            titles = QVBoxLayout()
            titles.setSpacing(0)
            small = QLabel("บิลปัจจุบัน")
            small.setObjectName("cartTitleSmall")
            self.cart_title = QLabel("รายการขาย")
            self.cart_title.setObjectName("cartTitleMain")
            titles.addWidget(small)
            titles.addWidget(self.cart_title)
            head_layout.addLayout(titles)
            head_layout.addStretch(1)
            hold = QPushButton("พักบิล")
            hold.setObjectName("cartTitleButton")
            hold.setMinimumHeight(48)
            hold.clicked.connect(self.hold_order)
            recall = QPushButton("เรียกบิล")
            recall.setObjectName("cartTitleButton")
            recall.setMinimumHeight(48)
            recall.clicked.connect(self.recall_order)
            clear_header = QPushButton("ล้างบิล")
            clear_header.setObjectName("cartTitleButton")
            clear_header.setMinimumHeight(48)
            clear_header.clicked.connect(self.clear_order)
            head_layout.addWidget(hold)
            head_layout.addWidget(recall)
            head_layout.addWidget(clear_header)
            layout.addWidget(head)

            pulse = QWidget()
            pulse.setObjectName("shiftPulse")
            pulse_layout = QHBoxLayout(pulse)
            pulse_layout.setContentsMargins(0, 0, 0, 0)
            pulse_layout.setSpacing(0)
            self.pulse_values = []
            for caption in ("บิลวันนี้บนเครื่อง", "ยอดขาย", "เฉลี่ย/บิล"):
                cell = QWidget()
                cell.setObjectName("pulseCell")
                cell_layout = QVBoxLayout(cell)
                cell_layout.setContentsMargins(12, 5, 12, 5)
                cell_layout.setSpacing(0)
                label = QLabel(caption)
                label.setObjectName("pulseCaption")
                value = QLabel("-")
                value.setObjectName("pulseValue")
                cell_layout.addWidget(label)
                cell_layout.addWidget(value)
                pulse_layout.addWidget(cell, 1)
                self.pulse_values.append(value)
            layout.addWidget(pulse)

            # เก็บไว้ให้โค้ดเดิมที่เขียนข้อความสถานะคนขายใช้ได้ แต่ไม่แสดงบนจอ
            self.cashier_label = QLabel()
            self.cashier_label.setVisible(False)
            layout.addWidget(self.cashier_label)

            self.table = QTableWidget(0, 5)
            self.table.setObjectName("cartTable")
            self.table.setHorizontalHeaderLabels(["#", "สินค้า", "จำนวน", "รวม", ""])
            self.table.verticalHeader().setVisible(False)
            self.table.setShowGrid(False)
            self.table.setAlternatingRowColors(True)
            self.table.setWordWrap(True)
            self.table.setSelectionBehavior(QTableWidget.SelectRows)
            self.table.setSelectionMode(QTableWidget.SingleSelection)
            self.table.setEditTriggers(QTableWidget.NoEditTriggers)
            self.table.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
            header = self.table.horizontalHeader()
            header.setSectionResizeMode(0, QHeaderView.Fixed)
            header.setSectionResizeMode(1, QHeaderView.Stretch)
            header.setSectionResizeMode(2, QHeaderView.Fixed)
            header.setSectionResizeMode(3, QHeaderView.Fixed)
            header.setSectionResizeMode(4, QHeaderView.Fixed)
            self.table.setColumnWidth(0, 32)
            self.table.setColumnWidth(2, 136)
            self.table.setColumnWidth(3, 96)
            self.table.setColumnWidth(4, 48)
            self.table.verticalHeader().setDefaultSectionSize(58)
            self.table.itemSelectionChanged.connect(self.on_line_selected)
            self.table.cellDoubleClicked.connect(lambda *_: self.open_line_editor())
            layout.addWidget(self.table, 1)

            self.cart_empty = QLabel("ยังไม่มีรายการขาย\nสแกนบาร์โค้ดหรือแตะสินค้าทางซ้าย")
            self.cart_empty.setObjectName("cartEmpty")
            self.cart_empty.setAlignment(Qt.AlignCenter)
            layout.addWidget(self.cart_empty, 1)

            edit_row = QHBoxLayout()
            edit_row.setContentsMargins(12, 6, 12, 6)
            edit_row.setSpacing(6)
            self.edit_line_button = QPushButton("แก้จำนวน / ราคา / ส่วนลด")
            self.edit_line_button.setObjectName("cartSecondary")
            self.edit_line_button.setMinimumHeight(48)
            self.edit_line_button.clicked.connect(self.open_line_editor)
            edit_row.addWidget(self.edit_line_button, 1)
            layout.addLayout(edit_row)

            totals = QWidget()
            totals.setObjectName("totalBox")
            totals_layout = QVBoxLayout(totals)
            totals_layout.setContentsMargins(16, 10, 16, 10)
            totals_layout.setSpacing(4)
            self.subtotal_label = QLabel()
            self.subtotal_label.setObjectName("totalLabel")
            self.vat_label = QLabel()
            self.vat_label.setObjectName("vatLine")
            grand_row = QHBoxLayout()
            grand_caption = QLabel("ยอดสุทธิ")
            grand_caption.setObjectName("totalPayableLabel")
            self.grand_label = QLabel()
            self.grand_label.setObjectName("grandTotal")
            self.grand_label.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
            grand_row.addWidget(grand_caption)
            grand_row.addStretch(1)
            grand_row.addWidget(self.grand_label)
            totals_layout.addWidget(self.subtotal_label)
            totals_layout.addWidget(self.vat_label)
            totals_layout.addLayout(grand_row)
            layout.addWidget(totals)

            self.pay_button = QPushButton("รับชำระเงิน   F12")
            self.pay_button.setObjectName("payBtn")
            self.pay_button.setMinimumHeight(58)
            self.pay_button.clicked.connect(self.pay)
            pay_wrap = QHBoxLayout()
            pay_wrap.setContentsMargins(12, 8, 12, 6)
            pay_wrap.addWidget(self.pay_button)
            layout.addLayout(pay_wrap)
            self.pay_shortcut = QShortcut(QKeySequence("F12"), self)
            self.pay_shortcut.activated.connect(self.pay)

            self.status = QLabel()
            self.status.setObjectName("queueBar")
            layout.addWidget(self.status)

            # ปุ่มตัวเลขย้ายไปอยู่ใน popup แก้รายการ หน้าขายจะได้มีที่ให้รายการสินค้า
            self.line_editor = QDialog(self)
            self.line_editor.setWindowTitle("แก้รายการ")
            editor_layout = QVBoxLayout(self.line_editor)
            self.line_editor_title = QLabel()
            self.line_editor_title.setObjectName("cardTitle")
            self.line_editor_title.setWordWrap(True)
            editor_layout.addWidget(self.line_editor_title)
            editor_layout.addWidget(self.build_numpad())
            done = QPushButton("เสร็จ")
            done.setObjectName("payBtn")
            done.setMinimumHeight(44)
            done.clicked.connect(self.line_editor.accept)
            editor_layout.addWidget(done)
            return panel

        def open_line_editor(self) -> None:
            line = self.order.selected
            if line is None:
                TouchMessage.information(self, "แก้รายการ", "แตะเลือกรายการในบิลก่อน")
                return
            self.line_editor_title.setText(f"{line.name}\nเลือกโหมด แล้วกดตัวเลข")
            self.line_editor.setMinimumWidth(380)
            self.line_editor.exec()
            self.refresh_order(keep_selection=True)

        def change_line_qty(self, index: int, delta: int) -> None:
            if not (0 <= index < len(self.order.lines)):
                return
            line = self.order.lines[index]
            if line.locked_qty:
                return
            if line.qty + delta <= 0:
                self.order.lines.pop(index)
                self.order.select(len(self.order.lines) - 1 if self.order.lines else None)
            else:
                line.qty += delta
                self.order.select(index)
            self.refresh_order()

        def delete_line(self, index: int) -> None:
            if 0 <= index < len(self.order.lines):
                self.order.select(index)
                self.order.remove_selected()
                self.refresh_order()

        def _layout_x(self, component_type: str, fallback: int) -> int:
            return next((int(item.get("x", fallback)) for item in layout_config.get("components", [])
                         if item.get("type") == component_type), fallback)

        def build_numpad(self) -> QWidget:
            box = QWidget()
            box.setObjectName("saleKeypad")
            grid = QGridLayout(box)
            grid.setSpacing(6)

            self.mode_group = QButtonGroup(box)
            for column, (mode, label) in enumerate([(QTY, "จำนวน"), (PRICE, "ราคา"), (DISCOUNT, "ส่วนลด")]):
                button = QPushButton(label)
                button.setMinimumHeight(44)
                button.setCheckable(True)
                button.setChecked(mode == QTY)
                button.clicked.connect(lambda _, value=mode: self.set_mode(value))
                self.mode_group.addButton(button)
                grid.addWidget(button, 0, column)

            for row, keys in enumerate(NUMPAD_KEYS, start=1):
                for column, key in enumerate(keys):
                    button = QPushButton("⌫" if key == "backspace" else key)
                    button.setMinimumHeight(52)
                    button.setObjectName("keypadKey")
                    button.clicked.connect(lambda _, value=key: self.press(value))
                    grid.addWidget(button, row, column)
            return box

        # ---------- พื้นที่สินค้า ----------

        def build_product_panel(self) -> QWidget:
            panel = QWidget()
            panel.setObjectName("catalog")
            layout = QVBoxLayout(panel)
            layout.setContentsMargins(14, 10, 14, 10)
            layout.setSpacing(8)

            heading = QHBoxLayout()
            titles = QVBoxLayout()
            titles.setSpacing(0)
            caption = QLabel("รายการสินค้า")
            caption.setObjectName("sectionCaption")
            title = QLabel("เลือกหรือสแกนเพื่อขาย")
            title.setObjectName("sectionTitle")
            titles.addWidget(caption)
            titles.addWidget(title)
            heading.addLayout(titles)
            heading.addStretch(1)
            self.catalog_count = QLabel()
            self.catalog_count.setObjectName("catalogCount")
            heading.addWidget(self.catalog_count)
            layout.addLayout(heading)

            self.scan = QLineEdit()
            self.scan.setObjectName("searchRow")
            self.scan.setPlaceholderText("⌕  สแกนบาร์โค้ด หรือค้นหาชื่อสินค้า")
            self.scan.setMinimumHeight(52)
            self.scan.returnPressed.connect(self.on_scan)
            self.scan.textChanged.connect(self.refresh_products)
            search_row = QHBoxLayout()
            search_row.setSpacing(8)
            search_row.addWidget(self.scan, 1)
            self.clear_search = QPushButton("×")
            self.clear_search.setObjectName("clearSearch")
            self.clear_search.setAccessibleName("ล้างช่องค้นหาสินค้า")
            self.clear_search.setFixedSize(56, 56)
            self.clear_search.clicked.connect(self.clear_product_search)
            search_row.addWidget(self.clear_search)
            layout.addLayout(search_row)

            self.scan_hint = QLabel("สแกนแล้วกด Enter เพิ่มลงบิล · สินค้าชั่งกรอกน้ำหนักเอง")
            self.scan_hint.setObjectName("scanHint")
            layout.addWidget(self.scan_hint)

            self.category_host = QWidget()
            self.category_bar = QHBoxLayout(self.category_host)
            self.category_bar.setContentsMargins(0, 2, 0, 2)
            self.category_bar.setSpacing(8)
            self.category_host.setVisible(True)
            layout.addWidget(self.category_host)

            self.grid_host = QWidget()
            self.grid_host.setObjectName("productGrid")
            self.grid = QGridLayout(self.grid_host)
            self.grid.setContentsMargins(0, 0, 0, 0)
            # การ์ดเรียงชิดบน ไม่ยืดแถวจนเหลือช่องว่างกลางจอ
            self.grid.setAlignment(Qt.AlignTop)
            self.product_scroll = QScrollArea()
            self.product_scroll.setHorizontalScrollBarPolicy(Qt.ScrollBarAlwaysOff)
            self.product_scroll.setObjectName("productScroll")
            self.product_scroll.setFrameShape(QFrame.NoFrame)
            self.product_scroll.setWidgetResizable(True)
            self.product_scroll.setWidget(self.grid_host)
            layout.addWidget(self.product_scroll, 1)

            self.build_category_bar()
            return panel

        def _product_columns_for_width(self) -> int:
            """Use the published count, reducing only when a narrow display cannot fit it."""
            configured = int(self.layout_runtime.get("product_columns", 4))
            available = self.grid_host.width()
            if available <= 0:
                return max(2, min(8, configured))
            gap = layout_metrics["gap"]
            minimum_tile = 140 if self.property("compact") else 150
            max_fit = max(2, (available + gap) // (minimum_tile + gap))
            return max(2, min(8, configured, max_fit))

        def build_category_bar(self) -> None:
            group = QButtonGroup(self)
            for name in categories(service.db):
                button = QPushButton(name)
                button.setObjectName("catPill")
                button.setMinimumHeight(48)
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
                old_tile = self.grid.takeAt(0).widget()
                old_tile.hide()
                old_tile.deleteLater()

            columns = self._product_columns_for_width()
            self._product_columns = columns
            self.grid.setHorizontalSpacing(layout_metrics["gap"])
            self.grid.setVerticalSpacing(layout_metrics["gap"])
            # Keep product cards at the published compact height. Stretching
            # them to fill the viewport made the product buttons dominate the
            # screen and left too little room for the customer-facing bill.
            # Extra products remain available by scrolling inside this panel.
            row_height = max(96, layout_metrics["card"])
            term = self.scan.text().strip()
            # ค้นได้ทั้งชื่อ รหัส และบาร์โค้ด ตัวเลขสั้นยังไม่กรอง กันตารางกระพริบระหว่างเริ่มยิงสแกน
            search = "" if term.isdigit() and len(term) < 4 else term
            shown = 0
            for index, product in enumerate(product_grid(service.db, category=self.category, search=search)):
                price, _ = service.effective_price(int(product["id"]), product["price"] or 0)
                sku = str(product["sku"] or "").strip()
                unit = str(product["unit_name"] or "หน่วย")
                tile = QPushButton()
                tile.setObjectName("productTile")
                tile.setCursor(Qt.PointingHandCursor)
                tile.setToolTip(str(product["name"] or ""))
                tile_layout = QVBoxLayout(tile)
                tile_layout.setContentsMargins(10, 8, 10, 8)
                tile_layout.setSpacing(2)
                code = QLabel(sku or "-")
                code.setObjectName("productCode")
                title = QLabel(str(product["name"] or ""))
                title.setObjectName("productName")
                title.setWordWrap(True)
                bottom = QHBoxLayout()
                unit_label = QLabel(unit)
                unit_label.setObjectName("productUnit")
                price_label = QLabel(f"฿{price:,.2f}")
                price_label.setObjectName("productPrice")
                bottom.addWidget(unit_label)
                bottom.addStretch(1)
                bottom.addWidget(price_label)
                tile_layout.addWidget(code)
                tile_layout.addWidget(title, 1)
                tile_layout.addLayout(bottom)
                for label in (code, title, unit_label, price_label):
                    label.setAttribute(Qt.WA_TransparentForMouseEvents)
                # กว้างตามคอลัมน์ ห้ามตั้งความกว้างขั้นต่ำจากจอเดิม ไม่งั้นการ์ดล้นจนมีแถบเลื่อนแนวนอน
                tile.setSizePolicy(QSizePolicy.Ignored, QSizePolicy.Fixed)
                tile.setFixedHeight(row_height)
                tile.clicked.connect(lambda _, row=product: self.add_product_row(row))
                shown += 1
                self.grid.addWidget(tile, index // columns, index % columns)
            for column in range(max(PRODUCT_TILE_COLUMNS, columns)):
                self.grid.setColumnStretch(column, 1 if column < columns else 0)
            self.catalog_count.setText(f"{shown} รายการ")

        # ---------- การกระทำ ----------

        def start_work(self):
            if self.ensure_sale_session():
                self.locked = False
                self.pages.setCurrentIndex(0)
                self.settings_shortcut.setEnabled(True)

        def lock_session(self):
            SettingsService(service.db).set_device_setting("current_order", self.order.snapshot())
            self.locked = True
            self.pay_shortcut.setEnabled(False)
            self.cashier = None
            self.shift_id = None
            self.opening_cash = None
            self.auth_button.setEnabled(True)
            self.refresh_runtime_context()
            self.cashier_label.setText("รายการขาย · ล็อกหน้าจอ")
            self.setWindowTitle(f"JET POS v{APP_VERSION} — ล็อกหน้าจอ")
            self.pages.setCurrentWidget(self.lock_page)

        def changeEvent(self, event):
            if event.type() == QEvent.WindowStateChange and self.isMinimized() and hasattr(self, "pages"):
                self.lock_session()
                dialogs = self.findChildren(QDialog)
                for child in dialogs:
                    child._suppress_restore = True
                for child in dialogs:
                    child.reject()
                for child in dialogs:
                    child._suppress_restore = False
            super().changeEvent(event)

        def build_close_dialog(self):
            dialog = TouchDialog(self, "ปิดโปรแกรม JET POS", "กะและบิลค้างจะเก็บไว้ในเครื่อง")
            dialog.body.addWidget(QLabel(f"ตะกร้า {len(self.order.lines)} รายการ · คิวรอส่ง {service.pending_sync_count()} รายการ"))
            dialog.body.addWidget(QLabel("มีกะเปิดอยู่" if service.db.execute("SELECT 1 FROM shifts WHERE terminal_id=? AND status='open'", (terminal_id,)).fetchone() else "ไม่มีกะเปิด"))
            resume = dialog.confirm("กลับไปขายต่อ", dialog.reject)
            resume.setDefault(True)
            resume.setFocus()
            dialog.confirm("ล็อกหน้าจอ", lambda: (self.lock_session(), dialog.reject()))
            dialog.body.addSpacing(32)
            def request_exit():
                if (self.order.lines or service.pending_sync_count()) and TouchMessage.question(
                    dialog, "ยืนยันปิดโปรแกรม", "มีตะกร้าค้างหรือบิลรอส่ง ยืนยันเก็บไว้แล้วปิดโปรแกรม?"
                ) != TouchMessage.Yes:
                    return
                dialog.accept()
            close = dialog.confirm("ปิดโปรแกรม", request_exit)
            close.setAutoDefault(False)
            close.setStyleSheet("background:#b91c1c; color:white; min-height:64px;")
            return dialog

        def closeEvent(self, event):
            if not self._allow_close:
                if self.build_close_dialog().exec() != QDialog.DialogCode.Accepted:
                    event.ignore()
                    return
            SettingsService(service.db).set_device_setting("current_order", self.order.snapshot())
            self._allow_close = True
            super().closeEvent(event)

        def show_z_report(self, auto_print=False):
            row = service.db.execute("SELECT * FROM shifts WHERE status = 'closed' ORDER BY closed_at DESC LIMIT 1").fetchone()
            if not row:
                TouchMessage.information(self, "ใบปิดกะ", "ยังไม่มีกะที่ปิดแล้ว")
                return
            report = service.shift_cash_summary(row['id'])
            dialog = TouchDialog(self, "ใบปิดกะ", f"สรุปกะ {row['id']} · ปิดกะแล้ว")
            saved = SettingsService(service.db).get_device_setting('last_z_report', {})
            authoritative = isinstance(saved, dict) and saved.get('id') is not None and row['server_id'] is not None and int(saved['id']) == int(row['server_id'])
            expected = money(str(saved['expected_cash'])) if authoritative and saved.get('expected_cash') is not None else report.expected_cash
            counted = money(str(saved['counted_cash'])) if authoritative and saved.get('counted_cash') is not None else report.counted_cash or Decimal(0)
            opened = local_datetime_text(row['opened_at'], fmt='%d/%m/%Y %H:%M')
            ended = local_datetime_text(row['closed_at'], fmt='%d/%m/%Y %H:%M')
            text = f"เปิด {opened}\nปิด {ended}\n" + ('ยืนยันจาก ERP\n' if authoritative else 'สรุปในเครื่อง\n')
            text += '\n'.join(f'{label} {value:,.2f} บาท' for label, value in [
                ('เงินทอนต้นกะ', report.opening_cash), ('ขายเงินสด', report.cash_sales),
                ('เงินเข้า', report.cash_in), ('นำส่งเงิน', report.drops), ('เบิกจ่าย', report.payouts),
                ('เงินสดที่ควรมี', expected), ('เงินสดนับจริง', counted), ('ส่วนต่าง', counted-expected)])
            if authoritative and saved.get('float_kept') is not None:
                text = f'กะ ERP {saved["id"]}\nเปิด {opened}\nปิด {ended}\nยืนยันจาก ERP'
            if not authoritative:
                from .payments import LABELS
                text += "\n" + "\n".join(f"{LABELS[method]} {amount:,.2f} บาท" for method, amount in service.shift_payment_totals(row['id']).items())
            local_totals = service.shift_payment_totals(row['id'])
            local_payment_fields = dict(zip(('cash_sales', 'transfer_sales', 'card_sales', 'cheque_sales'), (local_totals[k] for k in ('cash', 'transfer', 'credit_card', 'cheque'))))
            text += '\n' + z_float_text(saved if authoritative else local_payment_fields)
            dialog.body.addWidget(logo_label(height=64))
            scroll = QScrollArea()
            scroll.setWidgetResizable(True)
            content = QLabel(text)
            content.setWordWrap(True)
            scroll.setWidget(content)
            dialog.body.addWidget(scroll)
            dialog.resize(880, 700)
            def print_report():
                from .printers import print_text_to_windows_queue
                try:
                    profile = SettingsService(service.db).get_device_setting('windows_printer_queue', '')
                    if not profile:
                        raise ValueError('เลือกเครื่องพิมพ์ในหน้าตั้งค่าก่อน')
                    for copy in (1, 2):
                        print_text_to_windows_queue(f'Z Report · ใบที่ {copy}/2\n' + text, str(profile))
                except Exception as error:
                    TouchMessage.warning(dialog, "พิมพ์ไม่ได้", cashier_error(error))
            dialog.confirm("พิมพ์ Z Report ซ้ำ", print_report)
            if auto_print and SettingsService(service.db).get_device_setting("windows_printer_queue", ""):
                print_report()
            dialog.exec()

        def is_scale_product(self, product) -> bool:
            return is_scale_product(service.db, int(product["id"]), product["name"], product["sku"])

        def ask_weight(self, name: str, price: Decimal) -> tuple[float, bool]:
            while True:
                value, ok = ValueDialog.get_value(self, "กรอกน้ำหนัก", f"{name} · {price:,.2f} บาท/กก.")
                if not ok:
                    return 0.0, False
                try:
                    return parse_weight(value), True
                except ValueError as error:
                    TouchMessage.warning(self, "น้ำหนักไม่ถูกต้อง", cashier_error(error))

        def clear_product_search(self) -> None:
            self.scan.clear()
            self.scan.setFocus()

        def add_product_row(self, product) -> None:
            price, price_version = service.effective_price(int(product["id"]), product["price"] or 0)
            if price <= 0:
                TouchMessage.warning(self, "ยังไม่ได้ตั้งราคา", f"{product['name']} ยังไม่มีราคาขาย")
                return

            if self.is_scale_product(product):
                quantity, ok = self.ask_weight(product["name"], price)
            else:
                # การเลือกการ์ดสินค้าปกติเท่ากับยิงสินค้า 1 หน่วย
                # ถ้าต้องการหลายชิ้นให้กด + ในรายการขายหลังเพิ่มแล้ว
                quantity, ok = Decimal("1"), True
            if not ok:
                return

            self.order.add_product(OrderLine(
                product_id=int(product["id"]), name=product["name"], unit_name=product["unit_name"],
                qty=Decimal(str(quantity)), unit_price=price, is_vat=bool(product["is_vat"]), price_version=price_version,
            ))
            self.refresh_order()
            self.clear_product_search()

        def on_scan(self) -> None:
            scanned = self.scan.text().strip()
            if not scanned:
                return

            # หาบาร์โค้ดที่ลงทะเบียนไว้ก่อนเสมอ แล้วค่อยตีความเป็นป้ายชั่ง
            # กันสินค้านำเข้าที่ขึ้นต้น 800 ถูกอ่านเป็นป้ายชั่งแล้วคิดเงินผิด
            product = service.lookup_barcode(scanned)
            if product:
                try:
                    cart_line = service.barcode_cart_line(scanned)
                except ValueError as error:
                    TouchMessage.warning(self, "หน่วยขาย", cashier_error(error))
                    return
                # รหัส PLU ไม่มีน้ำหนักในตัว ต่างจากป้ายชั่งที่อ่านน้ำหนักได้เลย
                quantity = cart_line.qty
                if product["barcode_type"] == "SCALE_PLU":
                    weight, ok = self.ask_weight(product["name"], cart_line.unit_price)
                    if not ok:
                        return
                    quantity = Decimal(str(weight))
                self.order.add_product(OrderLine(
                    product_id=int(product["id"]), name=product["name"],
                    unit_name=product["barcode_unit_name"] or product["unit_name"],
                    qty=quantity, unit_price=cart_line.unit_price, price_version=cart_line.price_version,
                    is_vat=bool(product["is_vat"]), barcode=product["barcode"], source_barcode=scanned,
                    barcode_type=product["barcode_type"],
                ))
                self.finish_scan()
                return

            if decode_scale_label(service.db, scanned) is not None:
                try:
                    cart_line = scale_cart_line(service.db, scanned)
                except ValueError as error:
                    TouchMessage.warning(self, "ป้ายเครื่องชั่ง", cashier_error(error))
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

            # ไม่ใช่บาร์โค้ดเต็ม: เจอคนเดียวเพิ่มเลย เจอหลายรายการให้เลือกจากการ์ดที่กรองแล้ว
            matches = product_grid(service.db, search=scanned, limit=2)
            if len(matches) == 1:
                self.add_product_row(matches[0])
                self.finish_scan()
            elif not matches:
                TouchMessage.warning(self, "ไม่พบสินค้า", f"ไม่พบสินค้า {scanned}")

        def finish_scan(self) -> None:
            self.scan.clear()
            self.scan.setFocus()
            self.refresh_order()

        def set_mode(self, mode: str) -> None:
            if mode in (PRICE, DISCOUNT) and not self.approve_adjustment():
                # ไม่อนุมัติ = กลับไปโหมดจำนวน ปุ่มที่กดค้างไว้ต้องเด้งกลับด้วย
                self.order.set_mode(QTY)
                for button in self.mode_group.buttons():
                    button.setChecked(button.text() == "จำนวน")
                return
            self.order.set_mode(mode)

        def reset_mode_buttons(self) -> None:
            # บิลใหม่เริ่มที่โหมดจำนวนเสมอ และต้องขออนุมัติส่วนลดใหม่
            for button in self.mode_group.buttons():
                button.setChecked(button.text() == "จำนวน")

        def approve_adjustment(self) -> bool:
            """เจ้าของให้คนขายลดราคาได้เอง ชื่อคนขายถูกส่งขึ้น ERP พร้อมยอดส่วนลด"""
            if not self.order.adjustment_approved_by:
                self.order.adjustment_approved_by = f"คนขาย {self.cashier['name'] if self.cashier is not None else '-'}"
            return True


        def press(self, key: str) -> None:
            self.order.press(key)
            self.refresh_order(keep_selection=True)

        def on_line_selected(self) -> None:
            rows = self.table.selectionModel().selectedRows()
            self.order.select(rows[0].row() if rows else None)

        def remove_line(self) -> None:
            self.order.remove_selected()
            self.refresh_order()

        def hold_order(self):
            if not self.order.lines:
                return
            settings = SettingsService(service.db)
            held = settings.get_device_setting("held_orders", [])
            dialog = TextDialog(self, "พักบิล", "ตั้งชื่อบิลเพื่อเรียกกลับ", "บิลพัก")
            if dialog.exec() != QDialog.DialogCode.Accepted:
                return
            snapshot = {"snapshot": self.order.snapshot()}
            snapshot['hold_label'] = dialog.entry.text().strip() or 'บิลพัก'
            if online is not None and online.online and self.cashier is not None and self.shift_id is not None:
                try:
                    shift = service.db.execute("SELECT server_id FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()
                    if not shift or not shift['server_id']:
                        raise ValueError('ต้องส่งกะขึ้น ERP ก่อนพักบิลส่วนกลาง')
                    cart = []
                    for line in self.order.lines:
                        product = service.db.execute('SELECT server_id, sku FROM products WHERE id = ?', (line.product_id,)).fetchone()
                        if not product or not product['server_id']:
                            raise ValueError('สินค้ายังไม่ผูก ERP ให้ซิงก์สินค้าก่อน')
                        cart.append(dict(id=product['server_id'], qty=str(line.qty), name_th=line.name, sku_code=product['sku'], pos_price=str(line.unit_price)))
                    portable = json.loads(snapshot['snapshot'])
                    for line, remote in zip(portable['lines'], cart):
                        line['product_id'] = remote['id']
                    result = online.provisioning.api.post('/api/pos/held-bills', dict(branch_id=branch_id, shift_id=shift['server_id'], cashier_id=int(self.cashier['server_id']), label=snapshot['hold_label'], total_amount=str(self.order.grand_total()), payload=dict(cart=cart, python_snapshot=portable)))
                    if result.get('success') is False:
                        raise ValueError(result.get('message') or 'พักบิลส่วนกลางไม่สำเร็จ')
                    self.order.clear()
                    self.refresh_order()
                    return
                except Exception as error:
                    TouchMessage.warning(self, 'พักบิลไม่ได้', cashier_error(error))
                    return
            held.append(snapshot)
            settings.set_device_setting("held_orders", held)
            self.order.clear()
            self.refresh_order()

        def recall_order(self):
            if self.order.lines:
                TouchMessage.information(self, "เรียกบิล", "พักหรือล้างบิลปัจจุบันก่อน")
                return
            settings = SettingsService(service.db)
            held = settings.get_device_setting("held_orders", [])
            remote = []
            if online is not None and online.online and self.cashier is not None:
                try:
                    remote = online.provisioning.api.get(f"/api/pos/held-bills?branch_id={branch_id}&cashier_id={int(self.cashier['server_id'])}").get('held_bills', [])
                except Exception as error:
                    TouchMessage.warning(self, 'โหลดบิลพักส่วนกลางไม่ได้', cashier_error(error))
            choices = [(f"เครื่องนี้ · {item.get('hold_label', f'บิล {i+1}') if isinstance(item, dict) else f'บิล {i+1}'}", ('local', i)) for i, item in reversed(list(enumerate(held)))]
            choices += [(f"ส่วนกลาง · {item.get('label') or item.get('hold_no')} · {item.get('total_amount', '')}", ('remote', i)) for i, item in enumerate(remote)]
            if not choices:
                TouchMessage.information(self, 'บิลพัก', 'ยังไม่มีบิลพัก')
                return
            picker = ChoiceDialog(self, 'บิลพัก', choices)
            if picker.exec() != QDialog.DialogCode.Accepted:
                return
            kind, index = picker.choice
            if kind == 'local':
                selected = held.pop(index)
                self.order = Order.restore(selected['snapshot'] if isinstance(selected, dict) else selected)
                settings.set_device_setting('held_orders', held)
            else:
                selected = remote[index]
                try:
                    portable = selected.get('python_snapshot')
                    if portable:
                        portable = json.loads(json.dumps(portable))
                        for line in portable['lines']:
                            product = service.db.execute('SELECT id FROM products WHERE server_id = ?', (line['product_id'],)).fetchone()
                            if not product:
                                raise ValueError('ไม่พบสินค้าในเครื่อง ให้ซิงก์สินค้าก่อนเรียกบิล')
                            line['product_id'] = product['id']
                        restored = Order.restore(json.dumps(portable))
                    else:
                        restored = Order()
                        for line in selected.get('cart', []):
                            product = service.db.execute('SELECT * FROM products WHERE server_id = ?', (line['id'],)).fetchone()
                            if not product:
                                raise ValueError('ไม่พบสินค้าในเครื่อง ให้ซิงก์สินค้าก่อนเรียกบิล')
                            restored.add_product(OrderLine(product['id'],product['name'],product['unit_name'],Decimal(str(line['qty'])),Decimal(str(line.get('pos_price', product['price'])))))
                    result = online.provisioning.api.post(f"/api/pos/held-bills/{int(selected['id'])}/resume", {})
                    if result.get('success') is False:
                        raise ValueError(result.get('message') or 'เรียกบิลไม่ได้')
                    self.order = restored
                except Exception as error:
                    TouchMessage.warning(self, 'เรียกบิลไม่ได้', cashier_error(error))
                    return
            self.refresh_order()

        def clear_order(self) -> None:
            if self.order.lines and TouchMessage.question(
                self, "ล้างบิล", "ล้างรายการทั้งบิลใช่หรือไม่"
            ) != TouchMessage.Yes:
                return
            self.order.clear()
            self.reset_mode_buttons()
            self.refresh_order()

        def pay(self) -> None:
            if not self.order.lines:
                return
            if not self.ensure_sale_session():
                return
            dialog = PaymentDialog(self, self.order)
            if dialog.exec() != QDialog.DialogCode.Accepted:
                self.refresh_order(keep_selection=True)
                return

            try:
                document_no = service.next_document_no(terminal_id)
                sale_id = service.checkout(
                    document_no=document_no,
                    branch_id=branch_id, terminal_id=terminal_id, shift_id=self.shift_id,
                    cashier_id=int(self.cashier["id"]), lines=self.order.to_cart_lines(),
                    payment_method=dialog.payment_method, paid_amount=dialog.tendered(),
                    payment_reference=dialog.payment_reference.text().strip() or None,
                    cash_amount=money(dialog.amount.text() or "0") if dialog.payment_method == "mixed" else None,
                    qr_payload=dialog.qr_payload,
                    payment_confirmed=dialog.payment_method != "cash",
                    transfer_account_last4=dialog.transfer_account_last4.text().strip() or None,
                    adjustment_approved_by=self.order.adjustment_approved_by,
                    clear_current_order=True,
                )
                if online is not None:
                    online.worker.wake()  # ส่งบิลขึ้น ERP ทันที ไม่รอรอบถัดไป
            except Exception as error:
                TouchMessage.critical(self, "บันทึกบิลไม่สำเร็จ", cashier_error(error))
                return

            self.last_sale_id = sale_id
            printer_name = str(SettingsService(service.db).get_device_setting("windows_printer_queue", "") or "")
            if printer_name:
                try:
                    for copy in receipt_copies(service.db, sale_id):
                        print_text_to_windows_queue(copy, printer_name,
                            paper_width_mm=active_paper_width(service.db))
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
                else:
                    if dialog.payment_method in ("cash", "mixed") and SettingsService(service.db).get_device_setting("cash_drawer_enabled", "1") == "1":
                        try:
                            open_cash_drawer(printer_name)
                        except Exception as error:
                            TouchMessage.warning(self, "เปิดลิ้นชักไม่สำเร็จ", cashier_error(error))
            detail = (f"เงินทอน {self.order.change_for(dialog.tendered()):,.2f} บาท"
                      if dialog.payment_method == "cash" else f"รับชำระ{dialog.method_buttons[dialog.payment_method].text()}แล้ว")
            TouchMessage.information(self, "รับชำระแล้ว", f"บิล {document_no}\n{detail}")
            self.order.clear()
            self.reset_mode_buttons()
            self.refresh_order()

        def change_cashier_pin(self, code, current_pin):
            while True:
                new_pin, ok = ValueDialog.get_value(self, "เปลี่ยน PIN", "เลือก PIN ใหม่ด้วยตนเอง", pin=True)
                if not ok:
                    return False
                confirmation, ok = ValueDialog.get_value(self, "ยืนยัน PIN ใหม่", "ใส่ PIN ใหม่อีกครั้ง", pin=True)
                if not ok:
                    return False
                if new_pin != confirmation:
                    TouchMessage.warning(self, "PIN ไม่ตรงกัน", "ใส่ PIN ใหม่ให้ตรงกันทั้งสองครั้ง")
                    continue
                online.provisioning.change_cashier_pin(str(code), current_pin, new_pin)
                return True

        def pin_login(self):
            """ใส่ PIN อย่างเดียว ระบบรู้เองว่าใคร ทั้งออนไลน์และออฟไลน์"""
            pin, confirmed = ValueDialog.get_value(self, "เริ่มขาย", "ใส่รหัส PIN ของคุณ (4 หลัก)", pin=True, auto_pin=True)
            pin = pin.strip()
            if not confirmed or len(pin) != 4 or not pin.isascii() or not pin.isdigit():
                return None
            if online is None or not online.online:
                cashier = service.find_cashier_by_pin(pin)
                if cashier is None:
                    TouchMessage.warning(self, "เข้าใช้งานไม่ได้", "PIN ไม่ถูก ลองใหม่ หากยังไม่ได้ให้ต่ออินเทอร์เน็ตแล้วเข้าขายอีกครั้ง")
                return cashier
            try:
                selected = online.provisioning.online_cashier_login(pin)
                if selected.get("selection_required"):
                    # PIN ซ้ำจากข้อมูลเก่า: ให้เลือกชื่อ กันบิลเข้าผิดคน
                    people = selected["cashiers"]
                    picker = ChoiceDialog(self, "PIN ซ้ำกัน", [(str(p['name']), p) for p in people])
                    if picker.exec() != QDialog.DialogCode.Accepted:
                        return None
                    chosen = picker.choice
                    selected = online.provisioning.online_cashier_login(pin, cashier_server_id=int(chosen["id"]))
                if selected.get("must_change_pin"):
                    changed = self.change_cashier_pin(selected['cashier']['code'], pin)
                    if not changed:
                        return None
                online.worker.wake()
            except Exception as error:
                if "network:" not in str(error):
                    TouchMessage.warning(self, "เข้าใช้งานไม่ได้", "PIN ไม่ถูกหรือยังเข้าใช้งานไม่ได้ ลองใหม่ หากยังไม่ได้ให้ผู้ดูแลตรวจสิทธิ์และการเชื่อมต่อ")
                    return None
                online.online = False
                return service.find_cashier_by_pin(pin)
            return service.db.execute(
                "SELECT * FROM local_cashiers WHERE id = ?", (int(selected["local_cashier_id"]),)
            ).fetchone()

        def pick_seller(self):
            try:
                seller = SellerSelectionDialog()
                seller.setParent(self, seller.windowFlags())
                # Some Windows POS setups keep the on-screen keyboard above the
                # parent window. Show and raise the seller prompt before exec().
                seller.show()
                seller.raise_()
                seller.activateWindow()
                app.processEvents()
                seller_result = seller.exec()
            except Exception as error:
                TouchMessage.critical(
                    self,
                    "เปิดหน้าต่างเลือกคนขายไม่ได้",
                    f"POS เปิดหน้าต่างเริ่มขายไม่สำเร็จ: {error}\n\n"
                    "กรุณาถ่ายภาพข้อความนี้ส่งให้ฝ่าย IT",
                )
                return None
            if seller_result != QDialog.DialogCode.Accepted or seller.cashier is None:
                TouchMessage.information(
                    self,
                    "ยังไม่ได้เปิดกะ",
                    "กรุณาเลือกชื่อคนขายก่อน แล้วจึงใส่เงินทอนต้นกะเพื่อเปิดกะขาย",
                )
                return None
            return seller.cashier

        def ensure_sale_session(self) -> bool:
            """เลือกคนขาย ยืนยัน PIN ตามโหมดของ ERP แล้วเปิดกะเมื่อเริ่มขาย"""
            if self.cashier is not None and self.shift_id is not None:
                if online is not None and online.online:
                    server_shift = service.db.execute(
                        "SELECT server_id FROM shifts WHERE id = ?", (self.shift_id,)
                    ).fetchone()
                    if not server_shift or not server_shift["server_id"]:
                        online.worker.wake()
                        TouchMessage.information(
                            self,
                            "กำลังเชื่อมต่อ ERP",
                            "กะเปิดในเครื่องแล้ว แต่กำลังยืนยันกะกับ ERP\nกรุณารอสักครู่แล้วกดคิดเงินอีกครั้ง",
                        )
                        return False
                return True

            if SettingsService(service.db).get_device_setting('uncertain_float_close', None):
                TouchMessage.warning(self, "ต้องตรวจสถานะ ERP", "คำขอปิดกะก่อนหน้ายังไม่ทราบผล ห้ามขายหรือส่งปิดซ้ำจนกว่าจะกระทบยอด ERP")
                return False
            cashier = self.pin_login() if service.requires_cashier_pin() else self.pick_seller()
            if cashier is None:
                return False

            if not service.requires_cashier_pin() and online is not None and online.online:
                if not cashier["server_id"]:
                    TouchMessage.warning(
                        self,
                        "คนขายยังไม่พร้อม",
                        "รายชื่อนี้ยังไม่มีรหัสจาก ERP กรุณากดอัปเดตรายชื่อคนขายก่อน",
                    )
                    return False
                try:
                    selected = online.provisioning.select_cashier(
                        str(cashier["code"]), int(cashier["server_id"])
                    )
                    local_id = selected.get("local_cashier_id")
                    if local_id:
                        cashier = service.db.execute(
                            "SELECT * FROM local_cashiers WHERE id = ?", (int(local_id),)
                        ).fetchone() or cashier
                    online.worker.wake()
                except Exception as error:
                    message = str(error)
                    if "network:" in message:
                        online.online = False
                    else:
                        if "กรุณาระบุ PIN" in message:
                            message = (
                                "ERP ยังไม่เปิดโหมดเลือกชื่อคนขายโดยไม่ใช้ PIN\n"
                                "ให้ผู้ดูแลรัน: php artisan pos:passwordless enable"
                            )
                        TouchMessage.warning(self, "เลือกคนขายไม่ได้", message)
                        return False

            existing_shift = service.db.execute(
                "SELECT id, cashier_id, opening_cash FROM shifts WHERE terminal_id = ? AND status = 'open'",
                (terminal_id,),
            ).fetchone()
            if existing_shift and int(existing_shift["cashier_id"]) != int(cashier["id"]):
                owner = service.db.execute("SELECT name FROM local_cashiers WHERE id = ?", (existing_shift["cashier_id"],)).fetchone()
                owner_name = str(owner["name"] or "ไม่ทราบชื่อ") if owner else "ไม่ทราบชื่อ"
                TouchMessage.warning(self, "เริ่มขายไม่ได้", f"กะนี้เปิดโดย {owner_name}\nให้เจ้าของกะใช้ PIN ของตนเข้าเพื่อปิดกะก่อน หรือให้ผู้จัดการดำเนินการส่งมอบกะตามขั้นตอน")
                return False

            if existing_shift:
                opening_cash = money(existing_shift["opening_cash"])

            self.shift_policy = {}
            if online is not None and online.online and cashier['server_id']:
                try:
                    self.shift_policy = online.provisioning.api.get(
                        f"/api/pos/shift?branch_id={branch_id}&cashier_id={int(cashier['server_id'])}")
                except Exception as error:
                    TouchMessage.warning(self, "เริ่มขายไม่ได้", f"อ่านนโยบายกะไม่ได้: {cashier_error(error)}")
                    return False
            if not existing_shift:
                # Use our own numeric dialog: Qt's static getDouble dialog can be
                # covered by the Windows On-Screen Keyboard on touch POS devices.
                opening_dialog = OpeningShiftDialog(self)
                opening_dialog.show()
                opening_dialog.raise_()
                opening_dialog.activateWindow()
                app.processEvents()
                if opening_dialog.exec() != QDialog.DialogCode.Accepted:
                    return False
                opening_cash = opening_dialog.opening_cash

            try:
                shift_id = service.open_shift(
                    branch_id, terminal_id, int(cashier["id"]), opening_cash
                )
            except Exception as error:
                TouchMessage.warning(self, "เริ่มขายไม่ได้", cashier_error(error))
                return False

            self.cashier = cashier
            self.shift_id = shift_id
            self.opening_cash = opening_cash
            if online is not None:
                try:
                    # Never wait for ERP from the GUI thread. The sync worker opens the
                    # server shift first, then uploads any sale that belongs to it.
                    service.queue_shift_open(self.shift_id, opening_note=opening_dialog.opening_note if not existing_shift else "")
                    online.worker.wake()
                except Exception as error:
                    # The local shift is already committed and remains usable. Keep the
                    # reason visible in the normal sync queue instead of blocking sales.
                    service.record_auth_event(
                        str(self.cashier["code"] or ""), "shift_open_queue",
                        False,
                        str(error)[:500],
                    )

            self.cashier_label.setText(
                f"บิลปัจจุบัน · {self.cashier['name']} · เงินทอนต้นกะ {opening_cash:,.2f} บาท"
            )
            self.refresh_runtime_context()
            self.auth_button.setText("🔒 ล็อกหน้าจอ")
            self.auth_button.setEnabled(True)
            layout_version = layout_config.get("layout_version", layout_config.get("version", 1))
            self.setWindowTitle(f"JET POS v{APP_VERSION} — {self.cashier['name']} · Layout {layout_version}")
            self.refresh_order(keep_selection=True)
            self.locked = False
            self.refresh_cash_warning()
            self.pay_shortcut.setEnabled(True)
            self.pages.setCurrentIndex(0)
            return True

        def record_cash_movement(self, drop_only=False) -> None:
            if self.shift_id is None:
                TouchMessage.information(self, "ยังไม่มีกะขาย", "เริ่มขายและเปิดกะก่อนบันทึกรายการเงินสด")
                return
            labels = {"นำส่งเงินระหว่างกะ": "drop"}
            label = "นำส่งเงินระหว่างกะ"
            details = TouchDialog(self, label, "จำนวนเงิน · เลขซอง · เหตุผล")
            details.resize(940, 700)
            columns = QHBoxLayout()
            columns.setSpacing(14)
            details.body.addLayout(columns, 1)
            form_card = QFrame()
            form_card.setObjectName("card")
            form = QVBoxLayout(form_card)
            form.setContentsMargins(16, 16, 16, 16)
            form.setSpacing(8)
            columns.addWidget(form_card, 1)
            amount = QLineEdit()
            amount.setObjectName("shiftAmount")
            amount.setAlignment(Qt.AlignRight)
            reference = QLineEdit()
            reference.setMaxLength(80)
            reason_field = QLineEdit('นำส่งเงินระหว่างกะ' if labels[label] == 'drop' else '')
            for caption, field in [('จำนวนเงิน', amount), ('เลขซอง', reference), ('เหตุผล', reason_field)]:
                cap_lbl = QLabel(caption)
                cap_lbl.setStyleSheet(f"font-size:16px;font-weight:800;color:{PALETTE['text']};")
                form.addWidget(cap_lbl)
                form.addWidget(field)
            form.addStretch(1)
            columns.addWidget(NumPad(amount), 1)
            error_label = QLabel()
            error_label.setStyleSheet(f"color:{PALETTE['danger_btn']}; font-weight:700;")
            form.addWidget(error_label)
            def confirm_movement():
                try:
                    if cash(amount.text()) <= 0 or not reason_field.text().strip():
                        raise ValueError('กรอกยอดมากกว่า 0 และเหตุผล')
                    details.accept()
                except ValueError as error:
                    error_label.setText(str(error))
            confirm_btn = details.confirm('ยืนยัน', confirm_movement)
            confirm_btn.setObjectName("btnCheckoutPrimary")
            if details.exec() != QDialog.DialogCode.Accepted:
                return
            value = str(cash(amount.text()))
            reason = reason_field.text().strip()
            reference_no = reference.text().strip() or None
            try:
                movement_uuid = service.record_cash_movement(
                    shift_id=self.shift_id, movement_type=labels[label], amount=money(str(value)), reason=reason.strip(), reference_no=reference_no
                )
            except Exception as error:
                TouchMessage.warning(self, "บันทึกไม่สำเร็จ", cashier_error(error))
                return
            local = service.db.execute(
                "SELECT server_id FROM shifts WHERE id = ?", (self.shift_id,)
            ).fetchone()
            if online is not None and online.online and local and local["server_id"]:
                try:
                    online.provisioning.record_server_cash_movement(
                        server_shift_id=int(local["server_id"]), movement_type=labels[label],
                        amount=value, reason=reason.strip(), reference_no=reference_no, movement_uuid=movement_uuid,
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
                    TouchMessage.warning(self, "บันทึกในเครื่องแล้ว", f"ERP ยังไม่รับรายการนี้: {cashier_error(error)}\nระบบจะลองส่งให้อัตโนมัติ")
            self.status.setText("บันทึกรายการเงินสดแล้ว")
            self.refresh_cash_warning()

        def refresh_cash_warning(self):
            if self.locked or self.shift_id is None or self.cashier is None or online is None or not online.online:
                return
            try:
                policy = online.provisioning.api.get(f"/api/pos/shift?branch_id={branch_id}&cashier_id={int(self.cashier['server_id'])}")
                self.shift_policy = policy
                if (policy.get('shift') or {}).get('cash_warning') is True or policy.get('cash_warning') is True:
                    self.cash_warning_label.setText("⚠ เงินสดถึงเกณฑ์นำส่ง กรุณานำส่งเงินระหว่างกะ")
                else:
                    self.cash_warning_label.setText("")
            except Exception:
                pass  # Keep the last warning visible if ERP becomes unavailable.

        def expected_shift_cash(self):
            local = service.db.execute("SELECT server_id FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()
            expected = service.shift_cash_summary(self.shift_id).expected_cash
            if online is not None and online.online and local and local['server_id'] and self.cashier:
                try:
                    response = online.provisioning.api.get(f"/api/pos/shift?branch_id={branch_id}&cashier_id={int(self.cashier['server_id'])}")
                    live = response.get('shift')
                    if live and int(live['id']) == int(local['server_id']):
                        expected = money(str(live['expected_cash']))
                except Exception as error:
                    self.status.setText(f"ใช้ยอดในเครื่อง · ดึงยอด ERP ไม่สำเร็จ: {error}")
            return expected

        def close_float_shift(self, policy):
            local = service.db.execute("SELECT * FROM shifts WHERE id=?", (self.shift_id,)).fetchone()
            if not local['server_id']:
                TouchMessage.warning(self, "ปิดกะไม่ได้", "รอส่งกะขึ้น ERP ก่อน")
                return
            pending = service.db.execute("SELECT count(*) FROM sync_outbox WHERE status != 'synced'").fetchone()[0]
            if pending:
                TouchMessage.warning(self, "ปิดกะไม่ได้", "ต้องส่งบิลและรายการเงินสดค้างให้ครบก่อนปิดกะ")
                return
            protocol = FloatClose(online.provisioning.api, policy, int(local['server_id']))
            dialog = FloatCloseDialog(self, protocol, policy)
            if dialog.exec() != QDialog.DialogCode.Accepted:
                return
            try:
                SettingsService(service.db).set_device_setting('uncertain_float_close', dict(local_id=self.shift_id, server_id=local['server_id']))
                saved = protocol.close()
                # Server close succeeded. Reconcile local state without creating a retry event.
                with service.db:
                    service.db.execute("UPDATE shifts SET status='closed', closed_at=?, counted_cash=?, cash_difference=?, closing_note=? WHERE id=?",
                        (saved.get('closed_at') or service._now(), str(cash(saved['counted_cash'])),
                         str(Decimal(str(saved['cash_difference']))), dialog.note.text(), self.shift_id))
                SettingsService(service.db).set_device_setting('last_z_report', saved)
                SettingsService(service.db).set_device_setting('uncertain_float_close', None)
            except Exception as error:
                TouchMessage.warning(self, "ตรวจสถานะกะ ERP ก่อนทำต่อ", cashier_error(error))
                self.lock_session()
                return
            self.show_z_report(auto_print=True)
            self.lock_session()

        def close_current_shift(self) -> None:
            if self.shift_id is None:
                TouchMessage.information(self, "ยังไม่มีกะขาย", "ยังไม่มีกะที่เปิดอยู่")
                return
            policy = getattr(self, 'shift_policy', {})
            if online is not None and not online.online:
                TouchMessage.warning(self, "ปิดกะไม่ได้", "ต้องต่อเน็ตเพื่อปิดกะและตรวจยอดจาก ERP")
                return
            if online is not None and online.online and self.cashier and self.cashier['server_id']:
                try:
                    policy = online.provisioning.api.get(f"/api/pos/shift?branch_id={branch_id}&cashier_id={int(self.cashier['server_id'])}")
                    self.shift_policy = policy
                except Exception as error:
                    TouchMessage.warning(self, "ปิดกะไม่ได้", f"ต้องต่อเน็ตและอ่านสถานะ ERP ก่อนปิดกะ: {cashier_error(error)}")
                    return
            if supports_float(policy):
                if online is None or not online.online:
                    TouchMessage.warning(self, "ปิดกะไม่ได้", "ต้องต่อเน็ตเพื่อปิดกะและตรวจยอดจาก ERP")
                    return
                return self.close_float_shift(policy)
            dialog = CloseShiftDialog(self, self.expected_shift_cash())
            if dialog.exec() != QDialog.DialogCode.Accepted:
                return
            counted = dialog.amount.text()
            note = dialog.note.text()
            local_shift = service.db.execute("SELECT uuid, server_id FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()
            try:
                closed = service.close_shift(shift_id=self.shift_id, counted_cash=money(str(counted)), closing_note=note.strip())
            except Exception as error:
                TouchMessage.warning(self, "ปิดกะไม่ได้", cashier_error(error))
                return
            if online is not None and online.online and local_shift and local_shift["server_id"]:
                aggregate_uuid = f"shift:{local_shift['uuid']}:close"
                try:
                    response = online.provisioning.close_server_shift(
                        server_shift_id=int(local_shift["server_id"]), counted_cash=closed.counted_cash,
                        closing_note=note.strip() or None, idempotency_key=aggregate_uuid,
                    )
                    SettingsService(service.db).set_device_setting('last_z_report', response.get('shift') or {})
                    service.db.execute(
                        "UPDATE sync_outbox SET status = 'synced', synced_at = ?, last_error = NULL WHERE aggregate_uuid = ?",
                        (service._now(), aggregate_uuid),
                    )
                    service.db.commit()
                except Exception as error:
                    TouchMessage.warning(self, "ปิดกะในเครื่องแล้ว", f"ERP ยังไม่รับการปิดกะ: {cashier_error(error)}\nระบบจะลองส่งให้อัตโนมัติ")
            self.show_z_report(auto_print=True)
            difference = closed.cash_difference or Decimal("0")
            TouchMessage.information(self, "ปิดกะแล้ว", f"ยอดที่ควรมี {closed.expected_cash:,.2f} บาท\nส่วนต่าง {difference:,.2f} บาท")
            self.cashier = None
            self.shift_id = None
            self.opening_cash = None
            self.auth_button.setText("เลือกคนขายแล้ว")
            self.auth_button.setEnabled(True)
            self.cashier_label.setText(f"รายการขาย · ยังไม่ได้เริ่มขาย · v{APP_VERSION}")
            self.refresh_runtime_context()
            self.setWindowTitle(
                f"JET POS v{APP_VERSION} — พร้อมใช้งาน · Layout "
                f"{layout_config.get('layout_version', layout_config.get('version', 1))}"
            )
            self.lock_session()

        def open_settings(self) -> None:
            SettingsDialog(self, self.last_sale_id).exec()

        def show_last_receipt(self) -> None:
            if self.last_sale_id is None:
                TouchMessage.information(self, "ใบเสร็จ", "ยังไม่มีบิลในกะนี้")
                return
            dialog = TouchDialog(self, "ใบเสร็จล่าสุด", "ตรวจสอบและพิมพ์ใบเสร็จ")
            dialog.setWindowFlag(Qt.WindowCloseButtonHint, True)
            dialog.setMinimumWidth(420)
            layout = dialog.body
            body = QLabel(receipt_for(service.db, self.last_sale_id))
            body.setStyleSheet("background:%s;padding:16px;font-family:'Menlo','Courier New';font-size:12px;" % PALETTE["surface"])
            layout.addWidget(body)
            close = QDialogButtonBox(QDialogButtonBox.Close)
            close.rejected.connect(dialog.reject)
            layout.addWidget(close)
            dialog.exec()

        def show_history(self):
            rows = service.db.execute("SELECT id, document_no, server_receipt_no, sale_datetime, grand_total, sync_status, is_void FROM sales ORDER BY id DESC").fetchall()
            choices = []
            for row in rows:
                receipt_no = str(row['server_receipt_no'] or row['document_no'])
                if len(receipt_no) > 32:
                    receipt_no = f"{receipt_no[:15]}…{receipt_no[-12:]}"
                status = sale_status_label(row['sync_status']) + (' · ยกเลิกแล้ว' if row['is_void'] else '')
                choices.append((f"{receipt_no} · {local_datetime_text(row['sale_datetime'])}\nยอด {money(row['grand_total']):,.2f} บาท · {status}", row['id']))
            picker = ChoiceDialog(self, "ประวัติบิล · เลือกบิลเพื่อยกเลิก/รับคืน", choices, searchable=True)
            if picker.exec() != QDialog.DialogCode.Accepted:
                return
            sale_id = picker.choice
            dialog = TouchDialog(self, "รายละเอียดบิล", "เลือกยกเลิกบิล หรือรับคืนสินค้า")
            body = QLabel(receipt_for(service.db, sale_id))
            scroll = QScrollArea()
            scroll.setWidget(body)
            scroll.setWidgetResizable(True)
            dialog.body.addWidget(scroll)
            dialog.resize(560, 700)
            void_button = dialog.confirm("ยกเลิกบิล", lambda: self.receipt_action(sale_id, False))
            void_button.setStyleSheet(f"background:{PALETTE['danger']};color:white;font-weight:800;")
            dialog.confirm("รับคืนสินค้า", lambda: self.receipt_action(sale_id, True))
            dialog.exec()

        def receipt_action(self, sale_id, is_return):
            sale = service.db.execute("SELECT * FROM sales WHERE id = ?", (sale_id,)).fetchone()
            local_shift = service.db.execute("SELECT server_id FROM shifts WHERE id = ?", (self.shift_id,)).fetchone()
            title = 'รับคืนสินค้า' if is_return else 'ยกเลิกบิล'
            problem = receipt_action_problem(sale, local_shift, bool(online and online.online))
            if problem:
                TouchMessage.warning(self, title, problem)
                return
            is_today = service.db.execute("SELECT date(sale_datetime, '+7 hours') = date(?, '+7 hours') FROM sales WHERE id = ?", (service._now(), sale_id)).fetchone()[0]
            if not is_return and (sale['shift_id'] != self.shift_id or sale['is_void'] or not is_today):
                TouchMessage.warning(self, "ยกเลิกไม่ได้", "ยกเลิกได้เฉพาะบิลวันนี้ในกะนี้ที่ยังไม่ยกเลิก หากเป็นบิลเก่าให้เลือกรับคืนสินค้า")
                return
            title = 'รับคืนสินค้า' if is_return else 'ยกเลิกบิล'
            details = TextDialog(self, title, 'เหตุผล · รับคืนต้องอนุมัติใน ERP ก่อนคืนเงิน')
            reasons = QHBoxLayout()
            for reason in ("ลูกค้าเปลี่ยนใจ", "คิดสินค้าผิด", "ชำระเงินผิด"):
                button = QPushButton(reason)
                button.setMinimumHeight(56)
                button.clicked.connect(lambda checked=False, text=reason: details.entry.setText(text))
                reasons.addWidget(button)
            details.entry.setMaxLength(500)
            details.body.insertLayout(1, reasons)
            details.resize(900, 380)
            if details.exec() != QDialog.DialogCode.Accepted or not details.entry.text().strip():
                return
            managers = service.db.execute("SELECT server_id, name FROM local_cashiers WHERE active = 1 AND revoked_at IS NULL AND role = 'manager' AND server_id IS NOT NULL").fetchall()
            if not managers:
                TouchMessage.warning(self, title, "ยังไม่มีรายชื่อหัวหน้า ให้ผู้ดูแลโหลดรายชื่อพนักงานใหม่ แล้วลองอีกครั้ง")
                return
            picker = ChoiceDialog(self, "หัวหน้าที่อนุมัติ", [(r['name'], r['server_id']) for r in managers])
            if picker.exec() != QDialog.DialogCode.Accepted:
                return
            items = []
            if is_return:
                lines = service.db.execute("SELECT i.qty, p.server_id, p.name FROM sale_items i JOIN products p ON p.id = i.product_id WHERE i.sale_id = ?", (sale_id,)).fetchall()
                for line in lines:
                    qty, ok = ValueDialog.get_value(self, title, f"{line['name']} · สูงสุด {line['qty']}", '0')
                    if not ok:
                        return
                    if Decimal(qty) > Decimal(line['qty']):
                        TouchMessage.warning(self, title, "จำนวนคืนเกินจำนวนขาย")
                        return
                    if Decimal(qty) > 0:
                        items.append(dict(product_id=line['server_id'], qty=qty))
                if not items:
                    return
            pin, ok = ValueDialog.get_value(self, title, "หัวหน้าใส่รหัส PIN เพื่ออนุมัติ", pin=True)
            if not ok:
                return
            payload = dict(receipt_no=sale['server_receipt_no'], shift_id=local_shift['server_id'], reason=details.entry.text().strip(), approver_id=picker.choice, approver_pin=pin, terminal_code=terminal_id)
            if is_return:
                payload.update(refund_method='cash', items=items)
            try:
                try:
                    online.provisioning.verify_approver(picker.choice, pin, payload['reason'][:200])
                except Exception:
                    payload.pop('approver_pin', None)
                    pin = ''
                    TouchMessage.warning(self, title, 'อนุมัติไม่สำเร็จ ให้หัวหน้าลอง PIN ใหม่และตรวจการเชื่อมต่อ หากยังไม่ได้ให้ผู้ดูแลตรวจสิทธิ์หัวหน้า')
                    return
                try:
                    result = online.provisioning.api.post('/api/pos/receipt/' + ('return' if is_return else 'void'), payload)
                finally:
                    payload.pop('approver_pin', None)
                    pin = ''
                if result.get('success') is False:
                    raise ValueError(result.get('message') or 'ERP ไม่รับรายการ')
                if not is_return:
                    with service.db:
                        service.db.execute("UPDATE sales SET is_void = 1, void_reason = ?, voided_at = ? WHERE id = ?", (payload['reason'], service._now(), sale_id))
                TouchMessage.information(self, title, 'ส่งคำขอคืนสินค้าแล้ว ให้หัวหน้าอนุมัติใน ERP ก่อนคืนเงิน' if is_return else 'ยกเลิกบิลแล้ว')
            except Exception as error:
                TouchMessage.warning(self, title, "ยังยืนยันผลไม่ได้ ให้ผู้ดูแลตรวจบิลใน ERP ก่อนทำซ้ำ เพื่อไม่ให้ยกเลิกหรือรับคืนซ้ำ")
            finally:
                payload.pop('approver_pin', None)
                pin = ''

        def show_daily_sales(self) -> None:
            """Show the local terminal's sales without requiring a web server."""
            summary = service.daily_sales_summary()
            dialog = TouchDialog(self, "ยอดขายวันนี้ · เครื่องนี้", "สรุปจากฐานข้อมูลในเครื่อง")
            dialog.setWindowFlag(Qt.WindowCloseButtonHint, True)
            dialog.setMinimumWidth(520)
            dialog.resize(560, 560)
            layout = dialog.body

            title = QLabel(f"สรุปยอดวันที่ {summary.report_date.strftime('%d/%m/%Y')} · ข้อมูลจาก SQLite เครื่องนี้")
            title.setObjectName("cardTitle")
            layout.addWidget(title)
            status = QLabel(
                f"{summary.transaction_count} บิล · ยกเลิก {summary.void_count} บิล · "
                f"บิลรอส่ง {summary.pending_sync_count} บิล"
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
            recent.setHorizontalHeaderLabels(["บิลล่าสุด", "เวลา", "ยอด", "การส่งบิล"])
            recent.verticalHeader().setVisible(False)
            recent.horizontalHeader().setSectionResizeMode(QHeaderView.Stretch)
            for row_number, sale in enumerate(rows):
                for column, value in enumerate([
                    sale["document_no"], local_datetime_text(sale["sale_datetime"], fmt="%H:%M"),
                    f"{Decimal(str(sale['grand_total'])):,.2f}", sale_status_label(sale["sync_status"]),
                ]):
                    recent.setItem(row_number, column, QTableWidgetItem(str(value)))
            layout.addWidget(recent)
            receipt_actions = QPushButton("ยกเลิกบิล / รับคืนสินค้า")
            receipt_actions.setMinimumHeight(56)
            receipt_actions.clicked.connect(self.show_history)
            layout.addWidget(receipt_actions)

            close = QDialogButtonBox(QDialogButtonBox.Close)
            close.rejected.connect(dialog.reject)
            layout.addWidget(close)
            dialog.exec()

        # ---------- วาดใหม่ ----------

        def refresh_order(self, keep_selection: bool = False) -> None:
            # โปรซื้อครบจำนวนของวันนี้ — ERP หักเองทุกบิล หน้าจอต้องหักให้ตรงตั้งแต่ก่อนรับเงิน
            self.order.promotions = active_promotions(service.db)
            selected = self.order.selected_index
            self.table.blockSignals(True)
            self.table.setRowCount(0)
            SettingsService(service.db).set_device_setting("current_order", self.order.snapshot())
            promo = self.order.promotion_by_line()
            for index, line in enumerate(self.order.lines):
                row = self.table.rowCount()
                self.table.insertRow(row)
                name = line.name if not line.locked_qty else f"{line.name}  (ชั่ง)"
                detail = f"฿{line.unit_price:,.2f} / {line.unit_name}"
                if promo.get(index):
                    detail += f" · โปร -{promo[index]:,.2f}"
                if line.discount:
                    detail += f" · ลด {line.discount:,.2f}"
                seq = QTableWidgetItem(str(index + 1))
                seq.setTextAlignment(Qt.AlignCenter)
                self.table.setItem(row, 0, seq)
                self.table.setItem(row, 1, QTableWidgetItem(f"{name}\n{detail}"))
                qty_box = QWidget()
                qty_layout = QHBoxLayout(qty_box)
                qty_layout.setContentsMargins(2, 0, 2, 0)
                qty_layout.setSpacing(2)
                minus = QPushButton("−")
                plus = QPushButton("+")
                qty_text = QLabel(f"{line.qty:,.3f}".rstrip("0").rstrip("."))
                qty_text.setObjectName("qtyValue")
                qty_text.setAlignment(Qt.AlignCenter)
                for button in (minus, plus):
                    button.setObjectName("qtyButton")
                    button.setFixedSize(40, 40)
                    button.setEnabled(not line.locked_qty)
                minus.clicked.connect(lambda _, i=index: self.change_line_qty(i, -1))
                plus.clicked.connect(lambda _, i=index: self.change_line_qty(i, 1))
                qty_layout.addWidget(minus)
                qty_layout.addWidget(qty_text, 1)
                qty_layout.addWidget(plus)
                self.table.setCellWidget(row, 2, qty_box)
                total = QTableWidgetItem(f"฿{line.total - promo.get(index, 0):,.2f}")
                total.setTextAlignment(Qt.AlignRight | Qt.AlignVCenter)
                self.table.setItem(row, 3, total)
                delete = QPushButton("✕")
                delete.setObjectName("voidBtn")
                delete.setFixedSize(38, 38)
                delete.setToolTip("ลบรายการ")
                delete.clicked.connect(lambda _, i=index: self.delete_line(i))
                self.table.setCellWidget(row, 4, delete)
            for gift in self.order.free_items():
                row = self.table.rowCount()
                self.table.insertRow(row)
                self.table.setItem(row, 1, QTableWidgetItem(f"ของแถม {gift.name}"))
                self.table.setItem(row, 2, QTableWidgetItem(format(gift.qty.normalize(), "f")))
                self.table.setItem(row, 3, QTableWidgetItem("฿0.00"))
            if selected is not None and selected < self.table.rowCount():
                self.table.selectRow(selected)
            self.table.blockSignals(False)
            if not keep_selection:
                self.order.select(selected)

            rate = service.vat_rate()
            has_lines = bool(self.order.lines)
            self.table.setVisible(has_lines)
            self.cart_empty.setVisible(not has_lines)
            self.edit_line_button.setEnabled(has_lines)
            self.pay_button.setEnabled(has_lines)
            self.cart_title.setText(f"รายการขาย · {len(self.order.lines)} รายการ" if has_lines else "รายการขาย")
            self.subtotal_label.setText(
                f"รวมสินค้า ฿{self.order.subtotal():,.2f}      ส่วนลด ฿{self.order.discount_total():,.2f}"
            )
            self.vat_label.setText(f"รวม VAT {rate:g}% แล้ว ฿{self.order.vat_total(rate):,.2f}")
            self.grand_label.setText(f"฿{self.order.grand_total():,.2f}")
            try:
                summary = service.daily_sales_summary()
                bills = summary.transaction_count - summary.void_count
                average = summary.grand_total / bills if bills else Decimal("0")
                for label, text in zip(self.pulse_values, (f"{bills} บิล", f"฿{summary.grand_total:,.2f}", f"฿{average:,.2f}")):
                    label.setText(text)
            except Exception:  # noqa: BLE001 - ตัวเลขสรุปเป็นของประกอบ ห้ามทำให้หน้าขายพัง
                pass
            self.refresh_runtime_context()
            if online is not None:
                pending = getattr(online.worker, "pending", None)
                pending = pending if pending is not None else service.pending_sync_count()
                link = "เชื่อม ERP" if getattr(online.worker, "online", True) else "ออฟไลน์"
                self.status.setText(
                    f"{f'กะ {self.shift_id}' if self.shift_id else 'พร้อมใช้งาน · ยังไม่ได้เริ่มขาย'} · "
                    f"{f'เงินทอนต้นกะ {self.opening_cash:,.2f} บาท · ' if self.opening_cash is not None else ''}"
                    f"{link} · บิลรอส่ง {pending} ใบ"
                )
            else:
                self.status.setText(
                    f"{f'กะ {self.shift_id}' if self.shift_id else 'พร้อมใช้งาน · ยังไม่ได้เริ่มขาย'} · "
                    f"{f'เงินทอนต้นกะ {self.opening_cash:,.2f} บาท · ' if self.opening_cash is not None else ''}"
                    f"บิลรอส่ง {service.pending_sync_count()} ใบ"
                )

    if app is None:
        raise RuntimeError("POS ต้องเริ่ม QApplication จาก main.py ก่อนเปิดหน้าต่าง")
    app.setStyleSheet(runtime_style)
    window = PosWindow()
    window.dialog_types = dict(seller=SellerSelectionDialog, opening=OpeningShiftDialog, existing=ExistingShiftDialog, closing=CloseShiftDialog, float_closing=FloatCloseDialog, payment=PaymentDialog)
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
