"""หน้าจอขายแบบ Odoo — บิลอยู่ซ้าย ตารางสินค้าอยู่ขวา numpad แก้บรรทัดที่เลือก

ชั้นนี้ทำหน้าที่วาดและรับปุ่มเท่านั้น การคิดเงินทั้งหมดอยู่ใน order.py กับ
services.py ซึ่งมีเทสต์ครอบอยู่ เพราะตัวเลขที่ผิดในชั้นหน้าจอจะไม่มีอะไรจับได้
"""
from __future__ import annotations

from decimal import Decimal, InvalidOperation

from .barcode import decode_scale_label, scale_cart_line
from .order import ALL_CATEGORIES, DISCOUNT, PRICE, QTY, Order, OrderLine, categories, product_grid
from .services import PosService, money

STYLE = """
QMainWindow, QWidget { background: #f1f5f9; font-family: 'Sarabun', 'Tahoma'; font-size: 14px; }
#orderPanel { background: #ffffff; border-right: 1px solid #dbe3ea; }
#orderHead { background: #0f766e; color: #ffffff; padding: 12px 16px; font-weight: 700; }
#totalBox { background: #0f172a; color: #ffffff; padding: 14px 16px; }
#grandTotal { font-size: 30px; font-weight: 800; color: #ffffff; }
#totalLabel, #vatLine { color: #cbd5e1; font-size: 13px; }
QPushButton { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; font-size: 15px; }
QPushButton:hover { background: #f8fafc; }
QPushButton:checked { background: #0f766e; color: #ffffff; border-color: #0f766e; }
QPushButton#payBtn { background: #0f766e; color: #ffffff; font-size: 18px; font-weight: 700; border: 0; padding: 16px; }
QPushButton#voidBtn { color: #b91c1c; }
QPushButton#tile { text-align: left; padding: 12px; background: #ffffff; }
QPushButton#tile:hover { border-color: #0f766e; }
QTableWidget { border: 0; }
QHeaderView::section { background: #f8fafc; border: 0; border-bottom: 1px solid #e2e8f0; padding: 8px; font-weight: 700; }
QLineEdit { border: 1px solid #cbd5e1; border-radius: 8px; padding: 10px; font-size: 15px; }
#statusBar { color: #475569; font-size: 12.5px; padding: 6px 16px; }
"""

NUMPAD_KEYS = [
    ["7", "8", "9"],
    ["4", "5", "6"],
    ["1", "2", "3"],
    [".", "0", "backspace"],
]


def run_ui(service: PosService) -> None:
    try:
        from PySide6.QtCore import Qt
        from PySide6.QtWidgets import (
            QApplication, QButtonGroup, QDialog, QDialogButtonBox, QFormLayout, QGridLayout,
            QHBoxLayout, QHeaderView, QLabel, QLineEdit, QMainWindow, QMessageBox, QPushButton,
            QScrollArea, QTableWidget, QTableWidgetItem, QVBoxLayout, QWidget,
        )
    except ImportError as error:
        raise RuntimeError("ยังไม่ได้ติดตั้ง PySide6: python3 -m pip install -r requirements.txt") from error

    class LoginDialog(QDialog):
        def __init__(self):
            super().__init__()
            self.cashier = None
            self.setWindowTitle("POPSTAR POS — เข้าสู่ระบบ")
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

        def login(self):
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
            self.due.setStyleSheet("font-size:22px;font-weight:800;color:#0f172a;")
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
            self.change.setStyleSheet("font-size:18px;font-weight:700;color:#0f766e;")
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
                self.change.setStyleSheet("font-size:18px;font-weight:700;color:#b91c1c;")
            else:
                self.change.setText(f"เงินทอน {self.order.change_for(paid):,.2f} บาท")
                self.change.setStyleSheet("font-size:18px;font-weight:700;color:#0f766e;")

        def confirm(self):
            if self.tendered() < self.order.grand_total():
                QMessageBox.warning(self, "ยอดชำระไม่พอ", "รับเงินมาน้อยกว่ายอดที่ต้องชำระ")
                return
            self.accept()

    class PosWindow(QMainWindow):
        def __init__(self, cashier):
            super().__init__()
            self.cashier = cashier
            self.order = Order()
            self.category = ALL_CATEGORIES
            self.shift_id = service.open_shift(1, "PY-TEST-01", int(cashier["id"]), Decimal("0"))
            self.setWindowTitle(f"POPSTAR POS — {cashier['name']}")
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

            hold = QPushButton("ล้างบิล")
            hold.clicked.connect(self.clear_order)
            grid.addWidget(hold, 5, 2)

            pay = QPushButton("รับชำระเงิน")
            pay.setObjectName("payBtn")
            pay.clicked.connect(self.pay)
            grid.addWidget(pay, 6, 0, 1, 3)
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
                    branch_id=1, terminal_id="PY-TEST-01", shift_id=self.shift_id,
                    cashier_id=int(self.cashier["id"]), lines=self.order.to_cart_lines(),
                    payment_method="cash", paid_amount=dialog.tendered(),
                )
            except Exception as error:
                QMessageBox.critical(self, "บันทึกบิลไม่สำเร็จ", str(error))
                return

            QMessageBox.information(
                self, "รับชำระแล้ว",
                f"บิล {sale_id}\nเงินทอน {self.order.change_for(dialog.tendered()):,.2f} บาท",
            )
            self.order.clear()
            self.refresh_order()

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
        app.exec()
