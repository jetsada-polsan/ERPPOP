"""หน้าจอขายแบบ Odoo — บิลอยู่ซ้าย ตารางสินค้าอยู่ขวา numpad แก้บรรทัดที่เลือก

ชั้นนี้ทำหน้าที่วาดและรับปุ่มเท่านั้น การคิดเงินทั้งหมดอยู่ใน order.py กับ
services.py ซึ่งมีเทสต์ครอบอยู่ เพราะตัวเลขที่ผิดในชั้นหน้าจอจะไม่มีอะไรจับได้
"""
from __future__ import annotations

from decimal import Decimal, InvalidOperation
from string import Template

from .barcode import decode_scale_label, load_scale_profiles, scale_cart_line
from .mock_printer import company_details, receipt_for
from .order import ALL_CATEGORIES, DISCOUNT, PRICE, QTY, Order, OrderLine, categories, product_grid
from .services import PosService, money

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

# QSS ใช้ปีกกาเป็นไวยากรณ์ เลยแทนค่าด้วย $name แทน .format()
_STYLE_TEMPLATE = Template("""
/* สีและระยะตามภาพร่างที่อนุมัติแล้ว — โทน JET ชุดเดียวกับ ERP บนพื้นเทาอ่อน */
QMainWindow, QDialog, QWidget { background: $bg; color: $text; font-family: 'Sarabun','Tahoma'; font-size: 14px; }

/* ป้ายที่วางบนแถบสีต้องโปร่ง ไม่งั้นกินสีพื้นแอปมาเป็นแผ่นขาวทับแถบ */
#brandBar { background: $primary_dark; border-bottom: 3px solid $primary; }
#brandName, #brandMark, #brandRight,
#totalLabel, #vatLine, #grandTotal { background: transparent; }
#brandName { color: $surface; font-size: 18px; font-weight: 800; padding: 14px 0; }
#brandMark { color: $surface; border: 2px solid $surface; border-radius: 6px; padding: 5px 9px; font-weight: 800; }
#brandRight { color: $surface; font-size: 13px; }

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
QPushButton#tile { text-align: left; padding: 12px; background: $surface; }
QPushButton#tile:hover { border-color: $primary; }

QLineEdit, QComboBox { background: $surface; border: 1px solid $field; border-radius: 7px; padding: 9px 11px; font-size: 14.5px; }
QLineEdit:focus, QComboBox:focus { border-color: $primary; }

#orderPanel { background: $surface; border-right: 1px solid $border; }
#orderHead { background: $primary_dark; color: $surface; padding: 12px 16px; font-weight: 700; }
#totalBox { background: $text; color: $surface; padding: 14px 16px; }
#totalBox QLabel { background: transparent; }
#grandTotal { font-size: 30px; font-weight: 900; color: $surface; }
#totalLabel, #vatLine { color: $preview; font-size: 13px; }

QTableWidget { background: $surface; border: 0; }
QHeaderView::section { background: $primary_soft; border: 0; border-bottom: 1px solid $border; padding: 8px; font-weight: 700; }

/* กระดาษใบเสร็จวางบนพื้นเทาเหมือนวางบนโต๊ะ */
#receiptBg { background: $preview; border-radius: 6px; }
#receiptPaper { background: $surface; padding: 20px; font-family: 'Menlo','Courier New'; font-size: 12px; color: $paper_ink; }
#statusBar { color: $muted; font-size: 12.5px; padding: 6px 16px; }
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


def run_ui(service: PosService, online=None) -> None:
    # เครื่องที่ผูก ERP แล้วใช้ branch/terminal จริงจาก ping; เครื่อง demo ใช้ค่าเดิม
    branch_id = int(online.branch_id) if (online is not None and online.branch_id) else 1
    terminal_id = (online.terminal_id if (online is not None and online.terminal_id) else "PY-TEST-01")
    try:
        from PySide6.QtCore import Qt
        from PySide6.QtWidgets import (
            QApplication, QButtonGroup, QComboBox, QDialog, QDialogButtonBox, QFormLayout, QGridLayout,
            QHBoxLayout, QHeaderView, QLabel, QLineEdit, QMainWindow, QMessageBox, QPushButton,
            QScrollArea, QTableWidget, QTableWidgetItem, QVBoxLayout, QWidget,
        )
    except ImportError as error:
        raise RuntimeError("ยังไม่ได้ติดตั้ง PySide6: python3 -m pip install -r requirements.txt") from error

    class LoginDialog(QDialog):
        def __init__(self):
            super().__init__()
            self.cashier = None
            self.setWindowTitle("PopCentral POS — เข้าสู่ระบบ")
            form = QFormLayout(self)
            self.code = QLineEdit()
            self.code.setPlaceholderText("รหัสแคชเชียร์")
            self.pin = QLineEdit()
            self.pin.setEchoMode(QLineEdit.Password)
            self.pin.returnPressed.connect(self.login)
            submit = QPushButton("เข้าสู่ระบบ")
            submit.clicked.connect(self.login)
            form.addRow("รหัสแคชเชียร์", self.code)
            form.addRow("PIN", self.pin)
            form.addRow(submit)

        def _online_login(self) -> bool:
            pin = self.pin.text().strip()
            code = self.code.text().strip()
            try:
                result = online.provisioning.online_cashier_login(pin)
                if result.get("selection_required"):
                    # PIN กลางตรงหลายคน — เลือกด้วยรหัสแคชเชียร์ที่กรอก
                    match = next((c for c in result["cashiers"] if str(c.get("code")) == code), None)
                    if not match:
                        QMessageBox.warning(self, "เลือกพนักงาน", "PIN นี้มีหลายคน กรุณากรอกรหัสแคชเชียร์ของคุณด้วย")
                        return False
                    result = online.provisioning.online_cashier_login(pin, int(match["id"]))
            except Exception as error:
                QMessageBox.critical(self, "เข้าสู่ระบบไม่สำเร็จ", str(error))
                return False
            self.cashier = service.db.execute(
                "SELECT * FROM local_cashiers WHERE id = ?", (result["local_cashier_id"],)
            ).fetchone()
            return self.cashier is not None

        def login(self):
            if online is not None:
                if self._online_login():
                    self.accept()
                return
            self.cashier = service.login(self.code.text().strip(), self.pin.text())
            if not self.cashier:
                QMessageBox.warning(self, "เข้าสู่ระบบไม่สำเร็จ", "ไม่พบผู้ใช้หรือ PIN ไม่ถูกต้อง")
                return
            self.accept()

    class PaymentDialog(QDialog):
        """หน้าชำระเงิน — เงินทอนคำนวณสด ๆ ระหว่างพิมพ์ ไม่ต้องคิดในหัว"""

        def __init__(self, parent, order: Order):
            super().__init__(parent)
            self.order = order
            self.setWindowTitle("รับชำระเงิน")
            self.setMinimumWidth(360)
            layout = QVBoxLayout(self)

            self.due = QLabel(f"ยอดชำระ {order.grand_total():,.2f} บาท")
            self.due.setStyleSheet("font-size:22px;font-weight:800;color:%s;" % PALETTE["text"])
            layout.addWidget(self.due)

            self.amount = QLineEdit(str(order.grand_total()))
            self.amount.textChanged.connect(self.refresh_change)
            layout.addWidget(QLabel("เงินที่รับมา"))
            layout.addWidget(self.amount)

            quick = QHBoxLayout()
            for note in (100, 500, 1000):
                button = QPushButton(f"{note:,}")
                button.clicked.connect(lambda _, value=note: self.amount.setText(str(value)))
                quick.addWidget(button)
            exact = QPushButton("พอดี")
            exact.clicked.connect(lambda: self.amount.setText(str(order.grand_total())))
            quick.addWidget(exact)
            layout.addLayout(quick)

            self.change = QLabel()
            self.change.setStyleSheet("font-size:18px;font-weight:700;color:%s;" % PALETTE["success"])
            layout.addWidget(self.change)

            buttons = QDialogButtonBox(QDialogButtonBox.Ok | QDialogButtonBox.Cancel)
            buttons.accepted.connect(self.confirm)
            buttons.rejected.connect(self.reject)
            layout.addWidget(buttons)
            self.refresh_change()

        def tendered(self) -> Decimal:
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
            self.setMinimumSize(1080, 700)

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
            layout.addWidget(mark)
            layout.addWidget(name)
            layout.addStretch(1)
            layout.addWidget(right)
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
            self.paper = QComboBox()
            self.paper.addItems(["80 mm", "58 mm"])
            self.paper.currentIndexChanged.connect(self.refresh_preview)
            self.drawer = QComboBox()
            self.drawer.addItems(["เปิดเมื่อรับเงินสด", "ไม่เปิด"])
            form.addRow("ชื่อโปรไฟล์", self.profile_name)
            form.addRow("รุ่น/Driver", self.driver)
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

        def test_print(self) -> None:
            QMessageBox.information(self, "ทดสอบพิมพ์", "ส่งใบเสร็จตัวอย่างเข้าคิวพิมพ์แล้ว")

        def save_printer(self) -> None:
            QMessageBox.information(self, "บันทึกแล้ว", f"บันทึกโปรไฟล์ {self.profile_name.text()} แล้ว")

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
            form = QFormLayout(box)
            form.addRow("บิลรอส่ง", QLabel(f"{service.pending_sync_count()} ใบ"))
            form.addRow("อัตรา VAT ที่ใช้อยู่", QLabel(f"{service.vat_rate():g}%"))
            return box

        def backup_section(self) -> QWidget:
            box = QWidget()
            layout = QVBoxLayout(box)
            layout.addWidget(QLabel("สำรองด้วย VACUUM INTO ได้ไฟล์เดียวที่กู้คืนได้จริง"))
            layout.addWidget(QLabel("กู้คืนจะล้างไฟล์ -wal ที่ค้างอยู่ก่อนเสมอ"))
            return box

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

    class PosWindow(QMainWindow):
        def __init__(self, cashier):
            super().__init__()
            self.cashier = cashier
            self.order = Order()
            self.category = ALL_CATEGORIES
            self.shift_id = service.open_shift(branch_id, terminal_id, int(cashier["id"]), Decimal("0"))
            if online is not None and cashier["server_id"]:
                try:
                    online.provisioning.open_server_shift(
                        branch_id=branch_id, cashier_server_id=int(cashier["server_id"]),
                        opening_cash=Decimal("0"), local_shift_id=self.shift_id,
                    )
                except Exception:
                    # เปิดกะ ERP ไม่ได้ตอนนี้ ยังขายออฟไลน์ได้ บิลจะ sync เมื่อผูกกะสำเร็จภายหลัง
                    pass
            self.last_sale_id: int | None = None
            self.setWindowTitle(f"PopCentral POS — {cashier['name']}")
            self.setStyleSheet(STYLE)

            root = QWidget()
            columns = QHBoxLayout(root)
            columns.setContentsMargins(0, 0, 0, 0)
            columns.setSpacing(0)
            columns.addWidget(self.build_order_panel(), 4)
            columns.addWidget(self.build_product_panel(), 6)
            self.setCentralWidget(root)

            self.refresh_products()
            self.refresh_order()

        # ---------- ซ้าย: บิล ----------

        def build_order_panel(self) -> QWidget:
            panel = QWidget()
            panel.setObjectName("orderPanel")
            layout = QVBoxLayout(panel)
            layout.setContentsMargins(0, 0, 0, 0)
            layout.setSpacing(0)

            head = QLabel(f"บิลปัจจุบัน · {self.cashier['name']}")
            head.setObjectName("orderHead")
            layout.addWidget(head)

            self.table = QTableWidget(0, 4)
            self.table.setHorizontalHeaderLabels(["สินค้า", "จำนวน", "ราคา", "รวม"])
            self.table.verticalHeader().setVisible(False)
            self.table.setSelectionBehavior(QTableWidget.SelectRows)
            self.table.setEditTriggers(QTableWidget.NoEditTriggers)
            self.table.horizontalHeader().setSectionResizeMode(0, QHeaderView.Stretch)
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

        def build_numpad(self) -> QWidget:
            box = QWidget()
            grid = QGridLayout(box)

            self.mode_group = QButtonGroup(box)
            for column, (mode, label) in enumerate([(QTY, "จำนวน"), (PRICE, "ราคา"), (DISCOUNT, "ส่วนลด")]):
                button = QPushButton(label)
                button.setCheckable(True)
                button.setChecked(mode == QTY)
                button.clicked.connect(lambda _, value=mode: self.set_mode(value))
                self.mode_group.addButton(button)
                grid.addWidget(button, 0, column)

            for row, keys in enumerate(NUMPAD_KEYS, start=1):
                for column, key in enumerate(keys):
                    button = QPushButton("⌫" if key == "backspace" else key)
                    button.clicked.connect(lambda _, value=key: self.press(value))
                    grid.addWidget(button, row, column)

            sign = QPushButton("+/−")
            sign.clicked.connect(lambda: self.press("+/-"))
            grid.addWidget(sign, 5, 0)

            remove = QPushButton("ลบรายการ")
            remove.setObjectName("voidBtn")
            remove.clicked.connect(self.remove_line)
            grid.addWidget(remove, 5, 1)

            settings = QPushButton("ตั้งค่าเครื่องนี้")
            settings.clicked.connect(self.open_settings)
            grid.addWidget(settings, 5, 2)

            clear = QPushButton("ล้างบิล")
            clear.clicked.connect(self.clear_order)
            grid.addWidget(clear, 6, 0)

            receipt = QPushButton("ดูใบเสร็จล่าสุด")
            receipt.clicked.connect(self.show_last_receipt)
            grid.addWidget(receipt, 6, 1, 1, 2)

            pay = QPushButton("รับชำระเงิน")
            pay.setObjectName("payBtn")
            pay.clicked.connect(self.pay)
            grid.addWidget(pay, 7, 0, 1, 3)
            return box

        # ---------- ขวา: สินค้า ----------

        def build_product_panel(self) -> QWidget:
            panel = QWidget()
            layout = QVBoxLayout(panel)

            self.scan = QLineEdit()
            self.scan.setPlaceholderText("สแกนบาร์โค้ด ป้ายเครื่องชั่ง หรือพิมพ์ค้นหาสินค้า")
            self.scan.returnPressed.connect(self.on_scan)
            self.scan.textChanged.connect(self.refresh_products)
            layout.addWidget(self.scan)

            self.category_bar = QHBoxLayout()
            layout.addLayout(self.category_bar)

            self.grid_host = QWidget()
            self.grid = QGridLayout(self.grid_host)
            scroll = QScrollArea()
            scroll.setWidgetResizable(True)
            scroll.setWidget(self.grid_host)
            layout.addWidget(scroll, 1)

            self.build_category_bar()
            return panel

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

            term = self.scan.text().strip()
            # ตัวเลขล้วนคือกำลังยิงบาร์โค้ด ไม่ใช่ค้นหา — อย่าให้ตารางกระพริบระหว่างสแกน
            search = "" if term.isdigit() else term
            for index, product in enumerate(product_grid(service.db, category=self.category, search=search)):
                price = money(product["price"] or 0)
                tile = QPushButton(f"{product['name']}\n{price:,.2f} / {product['unit_name']}")
                tile.setObjectName("tile")
                tile.setMinimumHeight(72)
                tile.clicked.connect(lambda _, row=product: self.add_product_row(row))
                self.grid.addWidget(tile, index // 3, index % 3)

        # ---------- การกระทำ ----------

        def add_product_row(self, product) -> None:
            price = money(product["price"] or 0)
            if price <= 0:
                QMessageBox.warning(self, "ยังไม่ได้ตั้งราคา", f"{product['name']} ยังไม่มีราคาขาย")
                return
            self.order.add_product(OrderLine(
                product_id=int(product["id"]), name=product["name"], unit_name=product["unit_name"],
                qty=Decimal("1"), unit_price=price, is_vat=bool(product["is_vat"]),
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
                    qty=Decimal("1"), unit_price=money(product["price"] or 0),
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
            dialog = PaymentDialog(self, self.order)
            if dialog.exec() != QDialog.Accepted:
                return

            try:
                sale_id = service.checkout(
                    document_no=f"PYPOS-{self.shift_id}-{service.pending_sync_count() + 1:06d}",
                    branch_id=branch_id, terminal_id=terminal_id, shift_id=self.shift_id,
                    cashier_id=int(self.cashier["id"]), lines=self.order.to_cart_lines(),
                    payment_method="cash", paid_amount=dialog.tendered(),
                )
                if online is not None:
                    online.worker.wake()  # ส่งบิลขึ้น ERP ทันที ไม่รอรอบถัดไป
            except Exception as error:
                QMessageBox.critical(self, "บันทึกบิลไม่สำเร็จ", str(error))
                return

            self.last_sale_id = sale_id
            QMessageBox.information(
                self, "รับชำระแล้ว",
                f"บิล {sale_id}\nเงินทอน {self.order.change_for(dialog.tendered()):,.2f} บาท",
            )
            self.order.clear()
            self.refresh_order()

        def open_settings(self) -> None:
            SettingsDialog(self, self.last_sale_id).exec()

        def show_last_receipt(self) -> None:
            if self.last_sale_id is None:
                QMessageBox.information(self, "ใบเสร็จ", "ยังไม่มีบิลในกะนี้")
                return
            dialog = QDialog(self)
            dialog.setWindowTitle("ใบเสร็จล่าสุด")
            layout = QVBoxLayout(dialog)
            body = QLabel(receipt_for(service.db, self.last_sale_id))
            body.setStyleSheet("background:%s;padding:16px;font-family:'Menlo','Courier New';font-size:12px;" % PALETTE["surface"])
            layout.addWidget(body)
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
            self.status.setText(
                f"กะ {self.shift_id} · บิลรอส่ง {service.pending_sync_count()} ใบ · โหมด {self.order.mode}"
            )

    app = QApplication([])
    app.setStyleSheet(STYLE)
    login = LoginDialog()
    if login.exec() == QDialog.Accepted:
        window = PosWindow(login.cashier)
        window.resize(1280, 820)
        window.showMaximized()
        try:
            app.exec()
        finally:
            if online is not None:
                online.worker.stop()
