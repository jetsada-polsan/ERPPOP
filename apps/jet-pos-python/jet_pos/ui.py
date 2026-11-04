from __future__ import annotations

import json
from decimal import Decimal, InvalidOperation
from pathlib import Path
from uuid import uuid4

from PySide6.QtCore import QStandardPaths, Qt, QSettings
from PySide6.QtGui import QKeySequence, QShortcut, QTextDocument
from PySide6.QtPrintSupport import QPrintDialog, QPrinter
from PySide6.QtWidgets import QApplication, QCheckBox, QComboBox, QDialog, QDialogButtonBox, QDoubleSpinBox, QFileDialog, QFormLayout, QFrame, QGridLayout, QHBoxLayout, QInputDialog, QLabel, QLineEdit, QMainWindow, QMenu, QMessageBox, QPushButton, QScrollArea, QVBoxLayout, QWidget

from .api import PosApiClient, PosApiError, pair_device
from .local_store import LocalStore
from .models import CartLine, Product, Promotion
from .payments import Payment
from .pricing import price_cart
from .receipt import ReceiptLine, receipt_html
from .sync import sync_pending_checkouts


SAMPLE_PRODUCTS = (
    Product(1, "301261", "100พลัส เรกูลาร์ 370 มล. #", Decimal("20.00"), Decimal("-3"), "ชิ้น"),
    Product(2, "208078", "3A ฝาโดม 90 เจาะรู (50ใบ/แถว*40แถว/ลัง)", Decimal("20.00"), Decimal("-2"), "แถว"),
    Product(3, "208270", "3A ฝาโดม 95 ทรงสูง (100ใบ/แถว*20แถว/ลัง)", Decimal("36.00"), Decimal("-2"), "แถว"),
    Product(4, "208014", "3A ฝาโดม 95 ทรงสูง (50ใบ/แถว*40แถว/ลัง)", Decimal("22.00"), Decimal("-3"), "แถว"),
    Product(5, "205012", "A88 ถังหิ้วเล็ก(ชา) แพ็ค150g (40แพ็ค/ลัง)", Decimal("20.00"), Decimal("0"), "แพ็ค"),
    Product(6, "205011", "A88 ถังหิ้วเล็ก(ดำ) 150g (40แพ็ค/ลัง)", Decimal("15.00"), Decimal("-1"), "แพ็ค"),
    Product(7, "203053", "ANATOLI เส้นสปาเก็ตตี้ แพ็ค 500g. (20ห่อ/ลัง)", Decimal("39.00"), Decimal("-1"), "แพ็ค"),
    Product(8, "104011", "BB-B เบียร์-น็อค(ดำหนา) (กก10kg.)", Decimal("80.00"), Decimal("-12"), "กก."),
)


class SettingsDialog(QDialog):
    def __init__(self, parent: QWidget) -> None:
        super().__init__(parent)
        self.setWindowTitle("ตั้งค่าเครื่อง JET POS Python UAT")
        self.setMinimumWidth(560)
        self._settings = QSettings("MPM Food Group", "JetPosPythonUat")
        data_path = Path(QStandardPaths.writableLocation(QStandardPaths.StandardLocation.AppLocalDataLocation))
        data_path.mkdir(parents=True, exist_ok=True)
        self._store = LocalStore(data_path / "jet-pos-python.sqlite")
        layout = QVBoxLayout(self)
        title = QLabel("ตั้งค่าเครื่อง POS")
        title.setObjectName("dialogTitle")
        layout.addWidget(title)
        note = QLabel("สำหรับ UAT เท่านั้น: ใช้ ERP ทดสอบ/เครือข่ายภายใน ห้ามใช้ Token เครื่องขายจริง")
        note.setWordWrap(True)
        note.setObjectName("hint")
        layout.addWidget(note)
        form = QFormLayout()
        self.server = QLineEdit(str(self._settings.value("server_url", "")))
        self.server.setPlaceholderText("https://erp-uat.example.com")
        self.token = QLineEdit(str(self._settings.value("device_token", "")))
        self.token.setEchoMode(QLineEdit.EchoMode.Password)
        self.token.setPlaceholderText("Device Token ของเครื่องทดสอบ")
        self.pair_code = QLineEdit()
        self.pair_code.setPlaceholderText("Activation Code จากหน้าจัดการเครื่อง POS")
        form.addRow("ERP URL", self.server)
        form.addRow("Device Token", self.token)
        form.addRow("Activation Code", self.pair_code)
        layout.addLayout(form)
        self.production = QCheckBox("อนุมัติให้เครื่อง UAT นี้เชื่อม Production เพื่อทดสอบขายจริง")
        self.production.setChecked(self._settings.value("allow_production", False, bool))
        layout.addWidget(self.production)
        self.status = QLabel("")
        self.status.setWordWrap(True)
        layout.addWidget(self.status)
        actions = QHBoxLayout()
        pair = QPushButton("จับคู่เครื่อง")
        pair.clicked.connect(self._pair)
        test = QPushButton("ทดสอบการเชื่อมต่อ")
        test.clicked.connect(self._test_connection)
        save = QPushButton("บันทึกและโหลดสินค้า")
        save.setObjectName("primary")
        save.clicked.connect(self._save)
        cancel = QPushButton("ยกเลิก")
        cancel.clicked.connect(self.reject)
        actions.addWidget(pair)
        actions.addWidget(test)
        actions.addStretch()
        actions.addWidget(cancel)
        actions.addWidget(save)
        layout.addLayout(actions)

    def _client(self) -> PosApiClient:
        return PosApiClient(self.server.text(), self.token.text().strip(), allow_production=self.production.isChecked())

    def _test_connection(self) -> None:
        try:
            profile = self._client().ping()
            device = profile.get("device", {})
            name = device.get("name", "เครื่องทดสอบ") if isinstance(device, dict) else "เครื่องทดสอบ"
            self.status.setText(f"เชื่อมต่อสำเร็จ: {name}")
            self.status.setStyleSheet("color:#16794d;font-weight:700;")
        except (ValueError, PosApiError) as error:
            self.status.setText(str(error))
            self.status.setStyleSheet("color:#b42318;font-weight:700;")

    def _pair(self) -> None:
        try:
            paired = pair_device(self.server.text(), self.pair_code.text(), self.production.isChecked())
            self.token.setText(str(paired["token"]))
            terminal = str(paired.get("terminal_code") or "")
            self.status.setText(f"จับคู่เครื่องสำเร็จ {terminal}; กดบันทึกเพื่อเริ่มใช้งาน")
            self.status.setStyleSheet("color:#16794d;font-weight:700;")
        except (ValueError, PosApiError) as error:
            self.status.setText(str(error))
            self.status.setStyleSheet("color:#b42318;font-weight:700;")

    def _save(self) -> None:
        try:
            self._client().ping()
        except (ValueError, PosApiError) as error:
            self.status.setText(str(error))
            self.status.setStyleSheet("color:#b42318;font-weight:700;")
            return
        self._settings.setValue("server_url", self.server.text().strip())
        self._settings.setValue("device_token", self.token.text().strip())
        self._settings.setValue("allow_production", self.production.isChecked())
        self.accept()


class PaymentDialog(QDialog):
    def __init__(self, total: Decimal, parent: QWidget) -> None:
        super().__init__(parent)
        self.total = total
        self.setWindowTitle("รับชำระเงิน")
        self.setMinimumWidth(460)
        layout = QVBoxLayout(self)
        layout.addWidget(QLabel(f"ยอดสุทธิ <b style='font-size:24px'>฿{total:.2f}</b>"))
        form = QFormLayout()
        self.method = QComboBox()
        self.method.addItem("เงินสด", "cash")
        self.method.addItem("โอน / QR", "transfer")
        self.method.addItem("บัตรเครดิต", "credit_card")
        self.method.addItem("เช็ค", "cheque")
        self.method.addItem("เงินสด + โอน", "mixed")
        self.cash_received = QDoubleSpinBox(); self.cash_received.setRange(0, 10_000_000); self.cash_received.setDecimals(2); self.cash_received.setValue(float(total))
        self.cash_amount = QDoubleSpinBox(); self.cash_amount.setRange(0, 10_000_000); self.cash_amount.setDecimals(2); self.cash_amount.setValue(float(total))
        self.transfer_amount = QDoubleSpinBox(); self.transfer_amount.setRange(0, 10_000_000); self.transfer_amount.setDecimals(2)
        self.last4 = QLineEdit(); self.last4.setMaxLength(4); self.last4.setPlaceholderText("เช่น 1234")
        self.reference = QLineEdit(); self.reference.setMaxLength(80); self.reference.setPlaceholderText("เลขอ้างอิง (ถ้ามี)")
        form.addRow("วิธีชำระ", self.method)
        form.addRow("รับเงินสด", self.cash_received)
        form.addRow("เงินสดในบิลผสม", self.cash_amount)
        form.addRow("ยอดโอนในบิลผสม", self.transfer_amount)
        form.addRow("เลขท้ายบัญชีผู้โอน", self.last4)
        form.addRow("เลขอ้างอิง", self.reference)
        layout.addLayout(form)
        self.help = QLabel("")
        self.help.setStyleSheet("color:#697b88;")
        self.help.setWordWrap(True)
        layout.addWidget(self.help)
        actions = QDialogButtonBox(QDialogButtonBox.StandardButton.Cancel | QDialogButtonBox.StandardButton.Ok)
        actions.button(QDialogButtonBox.StandardButton.Ok).setText("ยืนยันรับชำระ")
        actions.accepted.connect(self._confirm)
        actions.rejected.connect(self.reject)
        layout.addWidget(actions)
        self.method.currentIndexChanged.connect(self._update_fields)
        self.cash_amount.valueChanged.connect(self._update_mixed_remaining)
        self._update_fields()

    def _update_fields(self) -> None:
        method = str(self.method.currentData())
        self.cash_received.setVisible(method == "cash")
        self.cash_amount.setVisible(method == "mixed")
        self.transfer_amount.setVisible(method == "mixed")
        transfer_visible = method in {"transfer", "mixed"}
        self.last4.setVisible(transfer_visible)
        self.reference.setVisible(method != "cash")
        self.help.setText({
            "cash": "ระบุจำนวนเงินที่รับ ระบบคำนวณเงินทอนให้",
            "transfer": "ยืนยันเงินเข้าก่อนบันทึก และกรอกเลขท้ายบัญชีผู้โอน 4 หลัก",
            "credit_card": "ตรวจสอบยอดจากเครื่องรูดบัตรก่อนยืนยัน",
            "cheque": "บันทึกเลขเช็คไว้ในเลขอ้างอิง",
            "mixed": "ยอดเงินสดและยอดโอนต้องรวมเท่ากับยอดสุทธิ",
        }[method])

    def _update_mixed_remaining(self) -> None:
        if str(self.method.currentData()) == "mixed":
            self.transfer_amount.blockSignals(True)
            self.transfer_amount.setValue(max(0, float(self.total) - self.cash_amount.value()))
            self.transfer_amount.blockSignals(False)

    def payment(self) -> Payment:
        return Payment(
            str(self.method.currentData()), self.total,
            cash_received=Decimal(str(self.cash_received.value())),
            cash_amount=Decimal(str(self.cash_amount.value())),
            transfer_amount=Decimal(str(self.transfer_amount.value())),
            payment_ref=self.reference.text(),
            transfer_account_last4=self.last4.text().strip(),
        )

    def _confirm(self) -> None:
        try:
            self.payment().payload_fields()
        except ValueError as error:
            QMessageBox.warning(self, "ตรวจสอบการชำระเงิน", str(error))
            return
        self.accept()


class CashMovementDialog(QDialog):
    def __init__(self, parent: QWidget) -> None:
        super().__init__(parent)
        self.setWindowTitle("เงินสดระหว่างกะ")
        self.setMinimumWidth(420)
        layout = QVBoxLayout(self)
        form = QFormLayout()
        self.movement = QComboBox()
        self.movement.addItem("เงินเข้า", "cash_in")
        self.movement.addItem("นำส่งเงิน", "drop")
        self.movement.addItem("เงินออก", "payout")
        self.amount = QDoubleSpinBox(); self.amount.setRange(0.01, 10_000_000); self.amount.setDecimals(2)
        self.reference = QLineEdit(); self.reference.setPlaceholderText("เลขอ้างอิง (ถ้ามี)")
        self.reason = QLineEdit(); self.reason.setPlaceholderText("เหตุผล")
        form.addRow("รายการ", self.movement)
        form.addRow("จำนวนเงิน", self.amount)
        form.addRow("เลขอ้างอิง", self.reference)
        form.addRow("เหตุผล", self.reason)
        layout.addLayout(form)
        actions = QDialogButtonBox(QDialogButtonBox.StandardButton.Cancel | QDialogButtonBox.StandardButton.Ok)
        actions.button(QDialogButtonBox.StandardButton.Ok).setText("บันทึก")
        actions.accepted.connect(self._confirm)
        actions.rejected.connect(self.reject)
        layout.addWidget(actions)

    def _confirm(self) -> None:
        if not self.reason.text().strip():
            QMessageBox.warning(self, "ระบุเหตุผล", "ต้องระบุเหตุผลของรายการเงินสด")
            return
        self.accept()

    def payload(self, shift_id: int) -> dict[str, object]:
        return {
            "shift_id": shift_id,
            "movement_type": str(self.movement.currentData()),
            "amount": self.amount.value(),
            "reference_no": self.reference.text().strip() or None,
            "reason": self.reason.text().strip(),
        }


class PosWindow(QMainWindow):
    def __init__(self) -> None:
        super().__init__()
        self.setWindowTitle("JET POS Python — UAT")
        self.resize(1440, 860)
        self._settings = QSettings("MPM Food Group", "JetPosPythonUat")
        self._products: tuple[Product, ...] = SAMPLE_PRODUCTS
        self._promotions: tuple[Promotion, ...] = ()
        self._cart: list[CartLine] = []
        self._total = QLabel("฿0.00")
        self._items = QLabel("0 รายการ · 0 ชิ้น")
        self._cart_body = QVBoxLayout()
        self._sync_label = QLabel("● โหมดตัวอย่าง UAT")
        self._catalog_count = QLabel("")
        self._grid = QGridLayout()
        self._profile: dict[str, object] = {}
        self._cashier: dict[str, object] = {}
        self._shift: dict[str, object] = {}
        self._member: dict[str, object] | None = None
        self._pay_button: QPushButton | None = None
        self._last_receipt: dict[str, object] | None = None
        self._build()
        QShortcut(QKeySequence("F8"), self, activated=self._focus_search)
        QShortcut(QKeySequence("F4"), self, activated=self._checkout)
        QShortcut(QKeySequence("Ctrl+L"), self, activated=self._confirm_clear_cart)

    def _build(self) -> None:
        root = QWidget()
        root.setObjectName("app")
        layout = QVBoxLayout(root)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.setSpacing(0)
        layout.addWidget(self._header())
        content = QHBoxLayout()
        content.setContentsMargins(18, 18, 18, 0)
        content.setSpacing(18)
        content.addWidget(self._catalog(), 2)
        content.addWidget(self._basket(), 1)
        layout.addLayout(content, 1)
        self.setCentralWidget(root)
        self.setStyleSheet("""
            QWidget#app { background:#f3f6f8; color:#1c3447; font-family:'Segoe UI'; }
            QLineEdit { padding:12px; border:1px solid #cbd7df; border-radius:7px; font-size:15px; background:white; }
            QPushButton { border:1px solid #cdd9e0; border-radius:7px; background:white; padding:9px 13px; font-weight:700; color:#29475b; }
            QPushButton:hover { background:#f0f7fa; border-color:#65aeca; }
            QPushButton#primary { background:#087faf; border-color:#087faf; color:white; }
            QLabel#dialogTitle { font-size:20px; font-weight:800; color:#075b86; }
            QLabel#hint { color:#697b88; }
        """)

    def _header(self) -> QWidget:
        bar = QFrame()
        bar.setFixedHeight(86)
        bar.setStyleSheet("background:#075b86;color:white;")
        row = QHBoxLayout(bar)
        row.setContentsMargins(25, 12, 25, 12)
        brand = QLabel("MPM  |  JET POS\nPOINT OF SALE")
        brand.setStyleSheet("font-size:18px;font-weight:800;")
        row.addWidget(brand)
        context = QLabel("สาขา / เครื่อง\nUAT · JET-POS-PYTHON")
        context.setStyleSheet("padding-left:25px;border-left:1px solid #62a9ca;font-weight:700;")
        row.addWidget(context)
        row.addStretch()
        self._sync_label.setStyleSheet("padding:10px 16px;border:1px solid #55c9ad;border-radius:7px;background:#08745f;font-weight:800;")
        row.addWidget(self._sync_label)
        def command(label: str, handler) -> QPushButton:
            button = QPushButton(label)
            button.clicked.connect(handler)
            button.setStyleSheet("QPushButton{padding:10px 14px;color:white;background:#075b86;border:1px solid #62a9ca;border-radius:7px;font-weight:800;} QPushButton:hover{background:#0b6b99;}")
            return button

        shift = command("▣ เข้ากะ", self._open_shift)
        close_shift = command("▤ ปิดกะ / Z", self._close_shift)
        hold_bill = command("▣ พักบิล", self._hold_bill)
        resume_bill = command("▤ เรียกบิล", self._resume_held_bill)
        print_receipt = command("🖨 พิมพ์บิล", self._print_last_receipt)
        settings = command("⚙ ตั้งค่า", self._open_settings)
        more = command("เพิ่มเติม ▾", lambda: None)
        menu = QMenu(more)
        for label, handler in (
            ("เปลี่ยน PIN", self._change_cashier_pin),
            ("สมาชิก", self._select_member),
            ("เงินสดกะ", self._cash_movement),
            ("ยกเลิกบิล", self._void_receipt),
            ("คืนสินค้า", self._return_receipt),
            ("สุขภาพเครื่อง", self._show_diagnostics),
            ("สำรองข้อมูล", self._backup_local_data),
            ("โหลดสินค้า", self._load_catalog),
            ("ส่งบิลค้าง", self._sync_queue),
        ):
            menu.addAction(label, handler)
        more.setMenu(menu)
        for button in (shift, close_shift, hold_bill, resume_bill, print_receipt, settings, more):
            row.addWidget(button)
        return bar

    def _catalog(self) -> QWidget:
        panel = QFrame()
        panel.setStyleSheet("QFrame{background:white;border:1px solid #d5e1e8;border-radius:10px;} QLabel{border:0;}")
        box = QVBoxLayout(panel)
        heading = QHBoxLayout()
        title = QLabel("รายการสินค้า\nเลือกหรือสแกนเพื่อขาย")
        title.setStyleSheet("font-size:15px;")
        heading.addWidget(title)
        heading.addStretch()
        self._catalog_count.setStyleSheet("color:#087faf;font-weight:800;")
        heading.addWidget(self._catalog_count)
        box.addLayout(heading)
        self._search = QLineEdit()
        self._search.setPlaceholderText("⌁  สแกนบาร์โค้ด หรือค้นหาชื่อสินค้า")
        self._search.textChanged.connect(self._filter_products)
        self._search.returnPressed.connect(self._scan_or_add_search)
        box.addWidget(self._search)
        quick = QLabel("ขายบ่อยบนเครื่องนี้     เลือกสินค้าหรือกด “โหลดสินค้า” หลังตั้งค่า UAT")
        quick.setStyleSheet("background:#fff8f8;color:#8c4853;padding:11px;border:1px solid #efd5d9;border-radius:7px;font-weight:700;")
        box.addWidget(quick)
        grid_widget = QWidget()
        grid_widget.setLayout(self._grid)
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        scroll.setWidget(grid_widget)
        scroll.setStyleSheet("border:0;")
        box.addWidget(scroll, 1)
        self._render_products(self._products)
        return panel

    def _basket(self) -> QWidget:
        panel = QFrame()
        panel.setStyleSheet("QFrame{background:white;border:1px solid #d5e1e8;border-radius:10px;} QLabel{border:0;}")
        box = QVBoxLayout(panel)
        header = QHBoxLayout()
        heading = QLabel("บิลปัจจุบัน\nรายการขาย")
        heading.setStyleSheet("font-size:16px;color:#075b86;")
        header.addWidget(heading)
        header.addStretch()
        header.addWidget(self._items)
        clear_cart = QPushButton("ล้างรายการ")
        clear_cart.clicked.connect(self._confirm_clear_cart)
        header.addWidget(clear_cart)
        box.addLayout(header)
        shift = QLabel("กะที่เปิดบนเครื่อง  9 บิล       ยอดขาย  ฿2,140.75       เฉลี่ย/บิล  ฿237.86")
        shift.setWordWrap(True)
        shift.setStyleSheet("padding:10px;background:#fff8f8;border:1px solid #f0dadd;border-radius:7px;color:#596b78;")
        box.addWidget(shift)
        cart = QWidget(); cart.setLayout(self._cart_body)
        cart_scroll = QScrollArea(); cart_scroll.setWidgetResizable(True); cart_scroll.setWidget(cart); cart_scroll.setStyleSheet("border:0;")
        box.addWidget(cart_scroll, 1)
        self._empty_cart()
        total_box = QFrame(); total_box.setStyleSheet("background:#075b86;border-radius:7px;color:white;")
        total_layout = QVBoxLayout(total_box); total_layout.addWidget(QLabel("ยอดรวมสุทธิ"))
        self._total.setAlignment(Qt.AlignmentFlag.AlignRight); self._total.setStyleSheet("font-size:36px;font-weight:900;color:white;")
        total_layout.addWidget(self._total); box.addWidget(total_box)
        pay = QPushButton("▣  รับชำระเงิน  ›"); pay.setEnabled(False); pay.clicked.connect(self._checkout)
        pay.setStyleSheet("QPushButton{padding:17px;background:#b3c0c8;color:white;border:0;border-radius:7px;font-size:17px;font-weight:800;}")
        box.addWidget(pay)
        self._pay_button = pay
        return panel

    def _render_products(self, products: tuple[Product, ...]) -> None:
        while self._grid.count():
            child = self._grid.takeAt(0)
            if child.widget(): child.widget().deleteLater()
        self._catalog_count.setText(f"{len(products)} สินค้าในเครื่อง")
        for index, product in enumerate(products):
            card = QPushButton()
            card.setMinimumHeight(138)
            stock = f"● คงเหลือ {product.stock_qty} {product.unit_name}"
            card.setText(f"{product.sku_code}\n{product.name_th}\n\n{stock}     ฿{product.pos_price:.2f}")
            stock_color = "#c53d37" if product.stock_qty <= 0 else "#168553"
            card.setStyleSheet(
                "QPushButton{text-align:left;padding:12px;background:#fff;border:1px solid #d7e4eb;"
                f"border-radius:8px;color:#1b4059;}} QPushButton:hover{{border:2px solid #1596cb;background:#effbff;}}"
                f"QPushButton{{selection-color:{stock_color};}}"
            )
            card.clicked.connect(lambda _, item=product: self._add_to_cart(item))
            self._grid.addWidget(card, index // 4, index % 4)

    def _filter_products(self, query: str) -> None:
        search = query.strip().lower()
        self._render_products(tuple(item for item in self._products if search in item.sku_code.lower() or search in item.name_th.lower()))

    def _focus_search(self) -> None:
        self._search.setFocus()
        self._search.selectAll()

    def _scan_or_add_search(self) -> None:
        barcode = self._search.text().strip()
        if not barcode:
            return
        local = next((product for product in self._products if product.sku_code == barcode), None)
        if local is not None:
            self._add_to_cart(local)
            self._search.clear()
            return
        if not self._profile:
            QMessageBox.information(self, "ไม่พบสินค้า", "โหลดสินค้าและเข้าเครื่อง POS ก่อนสแกนบาร์โค้ด")
            return
        try:
            result = self._client().scan(int(self._profile["branch_id"]), barcode)
            product_data = result["product"]
            if not isinstance(product_data, dict):
                raise PosApiError("ERP returned an invalid scanned product")
            product = self._product_from_api(product_data)
            known = next((item for item in self._products if item.id == product.id), product)
            try:
                qty = Decimal(str(result.get("qty") or 1))
            except (InvalidOperation, ValueError):
                qty = Decimal("1")
            self._add_to_cart(known, qty, barcode, str(result.get("barcode_type") or "custom"))
            self._search.clear()
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "สแกนสินค้าไม่สำเร็จ", str(error))

    def _open_settings(self) -> None:
        if SettingsDialog(self).exec() == QDialog.DialogCode.Accepted:
            self._load_catalog()

    def _load_catalog(self) -> None:
        server = str(self._settings.value("server_url", ""))
        token = str(self._settings.value("device_token", ""))
        if not server or not token:
            QMessageBox.information(self, "ยังไม่ได้ตั้งค่า", "กรอก ERP URL และ Device Token ของเครื่องทดสอบก่อน")
            self._open_settings(); return
        try:
            client = PosApiClient(server, token, allow_production=self._settings.value("allow_production", False, bool))
            profile = client.ping()
            self._profile = profile
            branch_id = int(profile.get("branch_id") or 0)
            if not branch_id: raise PosApiError("เครื่องทดสอบยังไม่ได้ผูกสาขา")
            self._products = tuple(self._product_from_api(row) for row in client.products(branch_id))
            self._promotions = tuple(
                promotion for promotion in (self._promotion_from_api(row, self._products) for row in client.promotions(branch_id))
                if promotion is not None
            )
            self._render_products(self._products)
            self._sync_label.setText(f"● ซิงก์สินค้า/โปรฯ แล้ว ({len(self._promotions)} โปรฯ)")
        except (ValueError, PosApiError) as error:
            self._sync_label.setText("● ซิงก์ไม่สำเร็จ")
            QMessageBox.warning(self, "โหลดสินค้าไม่สำเร็จ", str(error))

    def _client(self) -> PosApiClient:
        return PosApiClient(
            str(self._settings.value("server_url", "")),
            str(self._settings.value("device_token", "")),
            allow_production=self._settings.value("allow_production", False, bool),
        )

    def _open_shift(self) -> None:
        pin, accepted = QInputDialog.getText(self, "เข้าแคชเชียร์", "PIN คนขาย", QLineEdit.EchoMode.Password)
        if not accepted or not pin:
            return
        try:
            profile = self._client().ping()
            login = self._client().post("/cashier/login", {"pin": pin})
            if isinstance(login, dict) and login.get("selection_required") and isinstance(login.get("cashiers"), list):
                candidates = [candidate for candidate in login["cashiers"] if isinstance(candidate, dict)]
                labels = [f"{candidate.get('code', '-')}: {candidate.get('name', '-')}" for candidate in candidates]
                selected, accepted = QInputDialog.getItem(self, "เลือกแคชเชียร์", "PIN นี้ใช้ได้มากกว่าหนึ่งคน", labels, 0, False)
                if not accepted:
                    return
                cashier = candidates[labels.index(selected)]
                login = self._client().post("/cashier/login", {"pin": pin, "cashier_id": int(cashier["id"])})
            if not isinstance(login, dict) or not isinstance(login.get("cashier"), dict): raise PosApiError("ไม่พบแคชเชียร์จาก ERP")
            self._cashier = login["cashier"]
            credential = login.get("offline_credential")
            if isinstance(credential, dict):
                self._store.cache_offline_cashier(self._cashier, credential)
            branch_id, cashier_id = int(profile.get("branch_id") or 0), int(self._cashier.get("id") or 0)
            active = self._client().get(f"/shift?branch_id={branch_id}&cashier_id={cashier_id}")
            shift = active.get("shift") if isinstance(active, dict) else None
            if not isinstance(shift, dict):
                opening, accepted = QInputDialog.getDouble(self, "เปิดกะ", "เงินทอนเริ่มต้น", 0, 0, 10000000, 2)
                if not accepted: return
                opened = self._client().post("/shift/open", {"branch_id": branch_id, "cashier_id": cashier_id, "opening_cash": opening})
                shift = opened.get("shift") if isinstance(opened, dict) else None
            if not isinstance(shift, dict): raise PosApiError("เปิดกะไม่สำเร็จ")
            self._profile, self._shift = profile, shift
            self._settings.setValue("active_profile", json.dumps(self._profile, ensure_ascii=False))
            self._settings.setValue("active_cashier", json.dumps(self._cashier, ensure_ascii=False))
            self._settings.setValue("active_shift", json.dumps(self._shift, ensure_ascii=False))
            if self._pay_button: self._pay_button.setEnabled(bool(self._cart))
            self._sync_label.setText(f"● เปิดกะ {shift.get('shift_no', '')}")
            QMessageBox.information(self, "พร้อมขาย", f"เข้าแคชเชียร์ {self._cashier.get('name', '')} แล้ว")
        except (ValueError, PosApiError) as error:
            self._open_offline_shift(pin, str(error))

    def _open_offline_shift(self, pin: str, online_error: str) -> None:
        matches = self._store.offline_cashiers_by_pin(pin)
        if not matches:
            QMessageBox.warning(self, "เข้าออฟไลน์ไม่ได้", f"{online_error}\nไม่พบ PIN ที่เคยยืนยันบนเครื่องนี้")
            return
        cashier = matches[0]
        if len(matches) > 1:
            labels = [f"{candidate['code']}: {candidate['name']}" for candidate in matches]
            selected, accepted = QInputDialog.getItem(self, "เลือกแคชเชียร์ออฟไลน์", "PIN นี้ใช้ได้มากกว่าหนึ่งคน", labels, 0, False)
            if not accepted:
                return
            cashier = matches[labels.index(selected)]
        try:
            profile = json.loads(str(self._settings.value("active_profile", "")))
            previous_cashier = json.loads(str(self._settings.value("active_cashier", "")))
            shift = json.loads(str(self._settings.value("active_shift", "")))
        except (TypeError, ValueError, json.JSONDecodeError):
            QMessageBox.warning(self, "เข้าออฟไลน์ไม่ได้", "เครื่องนี้ยังไม่มีข้อมูลกะที่ยืนยันออนไลน์ กรุณาเชื่อม ERP และเปิดกะก่อน")
            return
        if not isinstance(profile, dict) or not isinstance(previous_cashier, dict) or not isinstance(shift, dict) or int(previous_cashier.get("id") or 0) != int(cashier["id"]):
            QMessageBox.warning(self, "เข้าออฟไลน์ไม่ได้", "ต้องใช้แคชเชียร์และกะเดิมที่เปิดไว้ตอนยังเชื่อม ERP")
            return
        self._profile, self._cashier, self._shift = profile, cashier, shift
        if self._pay_button:
            self._pay_button.setEnabled(bool(self._cart))
        self._sync_label.setText(f"● ออฟไลน์ · กะ {shift.get('shift_no', '')}")
        QMessageBox.information(self, "พร้อมขายออฟไลน์", "บิลจะเก็บในเครื่องและส่งขึ้น ERP เมื่อเชื่อมต่อได้")

    def _sync_queue(self) -> None:
        try:
            synced = sync_pending_checkouts(self._store, self._client())
            pending = len(self._store.pending_checkouts())
            self._sync_label.setText("● ซิงก์บิลแล้ว" if pending == 0 else f"● เหลือบิลรอส่ง {pending} บิล")
            QMessageBox.information(self, "ซิงก์บิล", f"ส่งบิลสำเร็จ {synced} บิล\nค้างส่ง {pending} บิล")
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "ส่งบิลค้างไม่สำเร็จ", str(error))

    def _show_diagnostics(self) -> None:
        health = self._store.health()
        history = self._store.recent_sales(10)
        latest = history[0].receipt_no if history else "ยังไม่มี"
        QMessageBox.information(self, "สุขภาพข้อมูลเครื่อง", "\n".join((
            f"ฐานข้อมูล: {health['integrity']}",
            f"บิลค้างส่ง: {health['queued']}",
            f"บิลรอตรวจสอบ: {health['review']}",
            f"บิลซิงก์แล้ว: {health['synced']}",
            f"บิลล่าสุด: {latest}",
        )))

    def _backup_local_data(self) -> None:
        default = str(Path(QStandardPaths.writableLocation(QStandardPaths.StandardLocation.DocumentsLocation)) / "jet-pos-python-backup.sqlite")
        destination, _ = QFileDialog.getSaveFileName(self, "สำรองข้อมูล POS", default, "SQLite Database (*.sqlite)")
        if not destination:
            return
        try:
            self._store.backup_to(Path(destination))
            QMessageBox.information(self, "สำรองข้อมูลแล้ว", f"บันทึกไฟล์สำรองที่\n{destination}")
        except OSError as error:
            QMessageBox.warning(self, "สำรองข้อมูลไม่สำเร็จ", str(error))

    def _change_cashier_pin(self) -> None:
        if not self._cashier:
            QMessageBox.information(self, "เปลี่ยน PIN", "เข้าแคชเชียร์ก่อนเปลี่ยน PIN")
            return
        code = str(self._cashier.get("code") or "")
        if not code:
            QMessageBox.warning(self, "เปลี่ยน PIN ไม่สำเร็จ", "ไม่พบรหัสแคชเชียร์")
            return
        current, accepted = QInputDialog.getText(self, "เปลี่ยน PIN", "PIN ปัจจุบัน", QLineEdit.EchoMode.Password)
        if not accepted or not current:
            return
        new_pin, accepted = QInputDialog.getText(self, "เปลี่ยน PIN", "PIN ใหม่ (4-20 หลัก)", QLineEdit.EchoMode.Password)
        if not accepted or not new_pin:
            return
        if not new_pin.isdigit() or not 4 <= len(new_pin) <= 20:
            QMessageBox.warning(self, "PIN ไม่ถูกต้อง", "PIN ใหม่ต้องเป็นตัวเลข 4-20 หลัก")
            return
        try:
            result = self._client().post("/cashier/pin", {"code": code, "current_pin": current, "new_pin": new_pin})
            QMessageBox.information(self, "เปลี่ยน PIN แล้ว", str(result.get("message") if isinstance(result, dict) else "เรียบร้อย"))
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "เปลี่ยน PIN ไม่สำเร็จ", str(error))

    def _close_shift(self) -> None:
        if not self._shift:
            QMessageBox.information(self, "ยังไม่มีการเปิดกะ", "เข้าแคชเชียร์และเปิดกะก่อนปิดกะ")
            return
        try:
            if self._store.pending_checkouts():
                QMessageBox.warning(self, "ยังปิดกะไม่ได้", "ยังมีบิลค้างส่ง ERP กรุณาส่งบิลค้างให้สำเร็จก่อน")
                return
            counted, accepted = QInputDialog.getDouble(self, "ปิดกะ", "ยอดเงินสดที่นับได้", float(self._shift.get("expected_cash") or 0), 0, 10_000_000, 2)
            if not accepted:
                return
            result = self._client().post("/shift/close", {"shift_id": int(self._shift["id"]), "counted_cash": counted})
            shift = result.get("shift") if isinstance(result, dict) else None
            if not isinstance(shift, dict):
                raise PosApiError("ERP ไม่ได้ส่งผลปิดกะกลับมา")
            report = "\n".join((
                f"Z Report: {shift.get('shift_no', '-')}",
                f"เงินทอนเริ่มต้น: ฿{Decimal(str(shift.get('opening_cash') or 0)):.2f}",
                f"ยอดเงินสด: ฿{Decimal(str(shift.get('cash_sales') or 0)):.2f}",
                f"ยอดโอน: ฿{Decimal(str(shift.get('transfer_sales') or 0)):.2f}",
                f"จำนวนบิล: {shift.get('receipt_count') or 0}",
                f"เงินสดตามระบบ: ฿{Decimal(str(shift.get('expected_cash') or 0)):.2f}",
                f"เงินสดที่นับ: ฿{Decimal(str(shift.get('counted_cash') or 0)):.2f}",
                f"ส่วนต่าง: ฿{Decimal(str(shift.get('cash_difference') or 0)):.2f}",
            ))
            QMessageBox.information(self, "ปิดกะสำเร็จ", report)
            self._print_z_report(report)
            self._shift = {}
            self._settings.remove("active_cashier")
            self._settings.remove("active_shift")
            self._settings.remove("active_profile")
            if self._pay_button:
                self._pay_button.setEnabled(False)
            self._sync_label.setText("● ปิดกะแล้ว")
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "ปิดกะไม่สำเร็จ", str(error))

    def _print_z_report(self, report: str) -> None:
        profile = self._profile.get("hardware_profile", {}) if isinstance(self._profile.get("hardware_profile", {}), dict) else {}
        if not profile.get("auto_print"):
            return
        document = QTextDocument()
        document.setHtml(f"<html><body><pre>{report}</pre></body></html>")
        printer = QPrinter(QPrinter.PrinterMode.HighResolution)
        document.print_(printer)

    def _cash_movement(self) -> None:
        if not self._shift:
            QMessageBox.information(self, "ยังไม่มีการเปิดกะ", "เปิดกะแคชเชียร์ก่อนบันทึกรายการเงินสด")
            return
        dialog = CashMovementDialog(self)
        if dialog.exec() != QDialog.DialogCode.Accepted:
            return
        try:
            result = self._client().post("/shift/cash-movement", dialog.payload(int(self._shift["id"])))
            shift = result.get("shift") if isinstance(result, dict) else None
            if isinstance(shift, dict):
                self._shift = shift
            QMessageBox.information(self, "บันทึกรายการเงินสดแล้ว", str(result.get("message") if isinstance(result, dict) else "เรียบร้อย"))
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "บันทึกรายการเงินสดไม่สำเร็จ", str(error))

    def _void_receipt(self) -> None:
        if not self._shift or not self._cashier:
            QMessageBox.information(self, "ยกเลิกบิลไม่ได้", "เปิดกะแคชเชียร์ก่อนยกเลิกบิล")
            return
        receipt_no, accepted = QInputDialog.getText(self, "ยกเลิกบิล", "เลขที่ใบเสร็จ")
        if not accepted or not receipt_no.strip():
            return
        reason, accepted = QInputDialog.getText(self, "ยกเลิกบิล", "เหตุผล")
        if not accepted or not reason.strip():
            return
        approver_id, accepted = QInputDialog.getInt(self, "อนุมัติยกเลิก", "รหัสผู้อนุมัติ", 0, 1)
        if not accepted:
            return
        approver_pin, accepted = QInputDialog.getText(self, "อนุมัติยกเลิก", "PIN ผู้อนุมัติ", QLineEdit.EchoMode.Password)
        if not accepted or not approver_pin:
            return
        try:
            result = self._client().post("/receipt/void", {
                "receipt_no": receipt_no.strip(), "shift_id": int(self._shift["id"]), "reason": reason.strip(),
                "approver_id": approver_id, "approver_pin": approver_pin,
            })
            QMessageBox.information(self, "ยกเลิกบิลแล้ว", str(result.get("message") if isinstance(result, dict) else "เรียบร้อย"))
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "ยกเลิกบิลไม่สำเร็จ", str(error))

    def _return_receipt(self) -> None:
        if not self._shift:
            QMessageBox.information(self, "คืนสินค้าไม่ได้", "เปิดกะแคชเชียร์ก่อนทำรายการคืนสินค้า")
            return
        receipt_no, accepted = QInputDialog.getText(self, "คืนสินค้า", "เลขที่ใบเสร็จ")
        if not accepted or not receipt_no.strip():
            return
        sku, accepted = QInputDialog.getText(self, "คืนสินค้า", "รหัสสินค้า (SKU)")
        if not accepted or not sku.strip():
            return
        product = next((item for item in self._products if item.sku_code == sku.strip()), None)
        if not product:
            QMessageBox.warning(self, "ไม่พบสินค้า", "ไม่พบ SKU นี้ในแคตตาล็อกเครื่อง กรุณาโหลดสินค้าใหม่")
            return
        qty, accepted = QInputDialog.getDouble(self, "คืนสินค้า", f"จำนวน {product.unit_name}", 1, 0.0001, 10_000_000, 4)
        if not accepted:
            return
        reason, accepted = QInputDialog.getText(self, "คืนสินค้า", "เหตุผล")
        if not accepted or not reason.strip():
            return
        method, accepted = QInputDialog.getItem(self, "คืนสินค้า", "คืนเงินโดย", ["เงินสด", "โอน"], 0, False)
        if not accepted:
            return
        approver_id, accepted = QInputDialog.getInt(self, "อนุมัติคืนสินค้า", "รหัสผู้อนุมัติ", 0, 1)
        if not accepted:
            return
        approver_pin, accepted = QInputDialog.getText(self, "อนุมัติคืนสินค้า", "PIN ผู้อนุมัติ", QLineEdit.EchoMode.Password)
        if not accepted or not approver_pin:
            return
        try:
            result = self._client().post("/receipt/return", {
                "receipt_no": receipt_no.strip(), "shift_id": int(self._shift["id"]), "reason": reason.strip(),
                "refund_method": "cash" if method == "เงินสด" else "transfer",
                "items": [{"product_id": product.id, "qty": qty}], "approver_id": approver_id, "approver_pin": approver_pin,
            })
            return_no = result.get("return_no", "") if isinstance(result, dict) else ""
            QMessageBox.information(self, "คืนสินค้าสำเร็จ", f"เลขที่ใบคืน {return_no}")
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "คืนสินค้าไม่สำเร็จ", str(error))

    def _hold_bill(self) -> None:
        if not self._cart or not self._profile or not self._cashier or not self._shift:
            QMessageBox.information(self, "พักบิลไม่ได้", "ต้องมีรายการขายและเปิดกะแคชเชียร์ก่อน")
            return
        label, accepted = QInputDialog.getText(self, "พักบิล", "ชื่อเรียกบิล (เช่น โต๊ะ 4 / ลูกค้ารอจ่าย)")
        if not accepted or not label.strip():
            return
        pricing = self._cart_pricing()
        cart = []
        for item in self._cart:
            product = item.product
            cart.append({
                "id": product.id,
                "qty": float(item.qty),
                "sku_code": product.sku_code,
                "name_th": product.name_th,
                "pos_price": float(product.pos_price),
                "barcode": item.barcode,
                "barcode_type": item.barcode_type,
            })
        payload = {
            "branch_id": int(self._profile["branch_id"]), "shift_id": int(self._shift["id"]), "cashier_id": int(self._cashier["id"]),
            "label": label.strip(), "total_amount": float(pricing.total), "payload": {"cart": cart},
        }
        try:
            result = self._client().post("/held-bills", payload)
            hold_no = result.get("hold_no") or result.get("held_bill_id") if isinstance(result, dict) else ""
            self._clear_cart()
            QMessageBox.information(self, "พักบิลแล้ว", f"พักบิล {hold_no} เรียบร้อย")
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "พักบิลไม่สำเร็จ", str(error))

    def _select_member(self) -> None:
        if not self._profile:
            QMessageBox.information(self, "เลือกสมาชิก", "เชื่อมต่อและโหลดข้อมูลเครื่องก่อน")
            return
        query, accepted = QInputDialog.getText(self, "เลือกสมาชิก", "รหัสสมาชิก / ชื่อ / เบอร์โทร")
        if not accepted:
            return
        if not query.strip():
            self._member = None
            self._sync_label.setText("● ไม่ใช้สมาชิกในบิล")
            return
        try:
            members = self._client().search_members(query.strip())
            if not members:
                QMessageBox.information(self, "ไม่พบสมาชิก", "ไม่พบสมาชิกจากคำค้นนี้")
                return
            labels = [f"{member.get('member_code', '-')}: {member.get('name', '-')} · แต้ม {member.get('points', 0)}" for member in members]
            selected, accepted = QInputDialog.getItem(self, "เลือกสมาชิก", "รายชื่อสมาชิก", labels, 0, False)
            if not accepted:
                return
            self._member = members[labels.index(selected)]
            self._sync_label.setText(f"● สมาชิก {self._member.get('member_code', '')}")
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "ค้นหาสมาชิกไม่สำเร็จ", str(error))

    def _resume_held_bill(self) -> None:
        if not self._profile or not self._cashier:
            QMessageBox.information(self, "เรียกบิลไม่ได้", "เข้าแคชเชียร์ก่อน")
            return
        try:
            response = self._client().get(f"/held-bills?branch_id={int(self._profile['branch_id'])}&cashier_id={int(self._cashier['id'])}")
            bills = response.get("held_bills", []) if isinstance(response, dict) else []
            if not isinstance(bills, list) or not bills:
                QMessageBox.information(self, "ไม่มีบิลพัก", "ไม่พบบิลพักในสาขานี้")
                return
            labels = [f"{bill.get('hold_no', '-')}: {bill.get('label', '-') } (฿{Decimal(str(bill.get('total_amount') or 0)):.2f})" for bill in bills if isinstance(bill, dict)]
            selected, accepted = QInputDialog.getItem(self, "เรียกบิลพัก", "เลือกบิล", labels, 0, False)
            if not accepted:
                return
            index = labels.index(selected)
            bill = bills[index]
            if not isinstance(bill, dict):
                return
            resumed = self._client().post(f"/held-bills/{int(bill['id'])}/resume", {})
            held_bill = resumed.get("held_bill", {}) if isinstance(resumed, dict) else {}
            payload = held_bill.get("payload", held_bill) if isinstance(held_bill, dict) else {}
            saved_cart = payload.get("cart", []) if isinstance(payload, dict) else []
            restored: list[CartLine] = []
            for line in saved_cart if isinstance(saved_cart, list) else []:
                if not isinstance(line, dict):
                    continue
                product = next((item for item in self._products if item.id == int(line.get("id") or 0)), None)
                if product is None:
                    continue
                try:
                    qty = Decimal(str(line.get("qty") or 0))
                except (InvalidOperation, ValueError):
                    continue
                if qty > 0:
                    restored.append(CartLine(product, qty, str(line.get("barcode") or "") or None, str(line.get("barcode_type") or "") or None))
            if not restored:
                raise PosApiError("บิลพักไม่มีสินค้าที่ตรงกับแคตตาล็อกในเครื่อง กรุณาโหลดสินค้าใหม่")
            self._cart = restored
            self._render_cart()
            QMessageBox.information(self, "เรียกบิลแล้ว", f"เรียกบิล {bill.get('hold_no', '')} แล้ว")
        except (ValueError, PosApiError) as error:
            QMessageBox.warning(self, "เรียกบิลไม่สำเร็จ", str(error))

    def _checkout(self) -> None:
        if not self._cart or not self._shift or not self._cashier or not self._profile:
            QMessageBox.warning(self, "ยังขายไม่ได้", "เข้าแคชเชียร์และเปิดกะก่อนรับชำระเงิน"); return
        pricing = self._cart_pricing()
        total = pricing.total
        dialog = PaymentDialog(total, self)
        if dialog.exec() != QDialog.DialogCode.Accepted:
            return
        payment = dialog.payment()
        try:
            payment_fields = payment.payload_fields()
        except ValueError as error:
            QMessageBox.warning(self, "ตรวจสอบการชำระเงิน", str(error))
            return
        payload = {"branch_id": int(self._profile["branch_id"]), "shift_id": int(self._shift["id"]), "cashier_id": int(self._cashier["id"]), **payment_fields, **({"member_id": int(self._member["id"])} if self._member and self._member.get("id") else {}), "bill_promotions": True, "mix_promotions": True, "vat_mode": "included", "items": [{"product_id": item.product_id, "qty": float(item.qty), "unit_price": float(item.unit_price), **({"barcode": item.barcode} if item.barcode else {}), **({"barcode_type": item.barcode_type} if item.barcode_type else {})} for item in pricing.items]}
        key = str(uuid4())
        self._store.queue_checkout(payload, key)
        self._store.save_sale(key, payload, float(pricing.total))
        try:
            sync_pending_checkouts(self._store, self._client())
            queued = self._store.checkout(key)
            if queued is None:
                raise PosApiError("ไม่พบบิลในคิวเครื่อง")
            if queued.status == "needs_review":
                QMessageBox.warning(self, "บิลรอตรวจสอบ", f"บันทึกบิลไว้ในเครื่องแล้ว แต่ ERP ปฏิเสธ: {queued.error}")
                return
            receipt = queued.receipt_no or "รอซิงก์"
            change = payment_fields.get("change_amount", 0)
            self._last_receipt = {
                "receipt_no": receipt,
                "pricing": pricing,
                "method": str(payment_fields["method"]),
                "change": Decimal(str(change)),
            }
            message = f"เลขที่ {receipt}\nเงินทอน {Decimal(str(change)):.2f}"
            if queued.status != "synced":
                message += "\nเก็บบิลไว้ในเครื่องแล้ว จะส่งซ้ำเมื่อเชื่อมต่อ ERP ได้"
            QMessageBox.information(self, "บันทึกบิลแล้ว", message)
            profile = self._profile.get("hardware_profile", {}) if isinstance(self._profile.get("hardware_profile", {}), dict) else {}
            if queued.status == "synced" and profile.get("auto_print"):
                self._print_last_receipt(silent=True)
            self._cart.clear()
            self._member = None
            while self._cart_body.count():
                child = self._cart_body.takeAt(0)
                if child.widget(): child.widget().deleteLater()
            self._empty_cart(); self._total.setText("฿0.00"); self._items.setText("0 รายการ · 0 ชิ้น")
            if self._pay_button: self._pay_button.setEnabled(False)
        except (PosApiError, ValueError) as error:
            QMessageBox.warning(self, "บันทึกบิลไม่สำเร็จ", str(error))

    def _print_last_receipt(self, silent: bool = False) -> None:
        if not self._last_receipt:
            if not silent:
                QMessageBox.information(self, "ยังไม่มีใบเสร็จ", "บันทึกบิลก่อน แล้วจึงพิมพ์ใบเสร็จล่าสุดได้")
            return
        pricing = self._last_receipt["pricing"]
        if not hasattr(pricing, "items"):
            QMessageBox.warning(self, "พิมพ์ไม่สำเร็จ", "ข้อมูลใบเสร็จไม่สมบูรณ์")
            return
        product_by_id = {product.id: product for product in self._products}
        lines = []
        for item in pricing.items:
            product = product_by_id.get(item.product_id)
            lines.append(ReceiptLine(product.sku_code if product else str(item.product_id), product.name_th if product else "สินค้า", item.qty, item.unit_price))
        company = self._profile.get("company", {}) if isinstance(self._profile.get("company", {}), dict) else {}
        document = QTextDocument()
        document.setHtml(receipt_html(str(self._last_receipt["receipt_no"]), lines, pricing.total, str(self._last_receipt["method"]), Decimal(str(self._last_receipt["change"])), company))
        printer = QPrinter(QPrinter.PrinterMode.HighResolution)
        if silent:
            document.print_(printer)
            return
        dialog = QPrintDialog(printer, self)
        if dialog.exec() == QDialog.DialogCode.Accepted:
            document.print_(printer)

    def closeEvent(self, event) -> None:  # type: ignore[override]
        self._store.close()
        event.accept()

    @staticmethod
    def _product_from_api(row: dict[str, object]) -> Product:
        def decimal_value(value: object) -> Decimal:
            try: return Decimal(str(value or 0))
            except (InvalidOperation, ValueError): return Decimal("0")
        return Product(int(row.get("id") or 0), str(row.get("sku_code") or "-"), str(row.get("name_th") or row.get("name") or "ไม่ระบุชื่อ"), decimal_value(row.get("pos_price") or row.get("price")), decimal_value(row.get("stock_qty") or row.get("available_qty")), str(row.get("unit_name") or row.get("sale_unit_name") or "ชิ้น"))

    @staticmethod
    def _promotion_from_api(row: dict[str, object], products: tuple[Product, ...]) -> Promotion | None:
        def decimal_value(value: object) -> Decimal | None:
            if value is None or value == "": return None
            try: return Decimal(str(value))
            except (InvalidOperation, ValueError): return None
        promo_type = str(row.get("promo_type") or "")
        supported = {"free_item", "discount", "bundle_price", "mix_bundle", "mix_free", "bill_discount", "bill_free_item", "bill_addon"}
        if promo_type not in supported:
            return None
        product_ids = tuple(int(item) for item in row.get("product_ids", []) if str(item).isdigit()) if isinstance(row.get("product_ids"), list) else ()
        free_id = int(row.get("free_product_id") or 0)
        free_product = next((product for product in products if product.id == free_id), None)
        return Promotion(
            id=int(row.get("id") or 0), name=str(row.get("name") or row.get("code") or "โปรโมชั่น"), promo_type=promo_type,  # type: ignore[arg-type]
            product_id=int(row["product_id"]) if row.get("product_id") else None, product_ids=product_ids,
            min_qty=decimal_value(row.get("min_qty")) or Decimal("1"), min_amount=decimal_value(row.get("min_amount")),
            discount_type=str(row.get("discount_type")) if row.get("discount_type") in {"percent", "baht"} else None,
            discount_value=decimal_value(row.get("discount_value")), max_discount=decimal_value(row.get("max_discount")),
            bundle_price=decimal_value(row.get("bundle_price")), free_product=free_product, free_qty=decimal_value(row.get("free_qty")),
        )

    def _cart_pricing(self):
        return price_cart(self._cart, self._promotions)

    def _empty_cart(self) -> None:
        self._cart_body.addStretch(); label = QLabel("🛒\nยังไม่มีรายการขาย\nสแกนสินค้า หรือเลือกจากด้านซ้าย")
        label.setAlignment(Qt.AlignmentFlag.AlignCenter); label.setStyleSheet("color:#8ba0ad;font-size:16px;")
        self._cart_body.addWidget(label); self._cart_body.addStretch()

    def _clear_cart(self) -> None:
        self._cart.clear()
        while self._cart_body.count():
            child = self._cart_body.takeAt(0)
            if child.widget(): child.widget().deleteLater()
        self._empty_cart(); self._total.setText("฿0.00"); self._items.setText("0 รายการ · 0 ชิ้น")
        if self._pay_button: self._pay_button.setEnabled(False)

    def _confirm_clear_cart(self) -> None:
        if not self._cart:
            return
        answer = QMessageBox.question(self, "ล้างรายการขาย", "ต้องการล้างสินค้าทั้งหมดจากบิลนี้ใช่หรือไม่?")
        if answer == QMessageBox.StandardButton.Yes:
            self._clear_cart()

    def _render_cart(self) -> None:
        while self._cart_body.count():
            child = self._cart_body.takeAt(0)
            if child.widget(): child.widget().deleteLater()
        pricing = self._cart_pricing()
        grouped: dict[tuple[int, str | None, str | None], CartLine] = {}
        for line in self._cart:
            key = (line.product.id, line.barcode, line.barcode_type)
            prior = grouped.get(key)
            grouped[key] = CartLine(line.product, line.qty + (prior.qty if prior else Decimal("0")), line.barcode, line.barcode_type)
        for item in grouped.values():
            product = item.product
            row = QFrame()
            row.setStyleSheet("QFrame{background:#f7fafb;border:1px solid #e1ebef;border-radius:6px;padding:4px;}")
            layout = QHBoxLayout(row)
            description = QLabel(f"{product.sku_code}  {product.name_th}\nจำนวน {item.qty.normalize()} {product.unit_name}")
            description.setStyleSheet("font-weight:700;color:#1b4059;")
            layout.addWidget(description, 1)
            edit = QPushButton("แก้จำนวน")
            edit.clicked.connect(lambda _, key=(product.id, item.barcode, item.barcode_type): self._edit_cart_quantity(key))
            remove = QPushButton("ลบ")
            remove.clicked.connect(lambda _, key=(product.id, item.barcode, item.barcode_type): self._remove_cart_line(key))
            amount = QLabel(f"฿{product.pos_price * item.qty:.2f}")
            amount.setStyleSheet("font-weight:800;color:#075b86;")
            layout.addWidget(edit); layout.addWidget(remove); layout.addWidget(amount)
            self._cart_body.addWidget(row)
        if pricing.promo_discount > 0:
            discount = QLabel(f"ส่วนลดโปรโมชั่น  -฿{pricing.promo_discount:.2f}")
            discount.setStyleSheet("color:#168553;font-weight:800;")
            self._cart_body.addWidget(discount)
        self._cart_body.addStretch()
        quantity = sum((line.qty for line in self._cart), Decimal("0"))
        self._total.setText(f"฿{pricing.total:.2f}"); self._items.setText(f"{len(grouped)} รายการ · {quantity.normalize()} ชิ้น")
        if self._pay_button: self._pay_button.setEnabled(bool(self._shift))

    def _edit_cart_quantity(self, key: tuple[int, str | None, str | None]) -> None:
        matching = [line for line in self._cart if (line.product.id, line.barcode, line.barcode_type) == key]
        if not matching:
            return
        current = sum((line.qty for line in matching), Decimal("0"))
        qty, accepted = QInputDialog.getDouble(self, "แก้จำนวน", f"จำนวน {matching[0].product.unit_name}", float(current), 0.0001, 10_000_000, 4)
        if not accepted:
            return
        self._cart = [line for line in self._cart if (line.product.id, line.barcode, line.barcode_type) != key]
        self._cart.append(CartLine(matching[0].product, Decimal(str(qty)), matching[0].barcode, matching[0].barcode_type))
        self._render_cart()

    def _remove_cart_line(self, key: tuple[int, str | None, str | None]) -> None:
        self._cart = [line for line in self._cart if (line.product.id, line.barcode, line.barcode_type) != key]
        if self._cart:
            self._render_cart()
        else:
            self._clear_cart()

    def _add_to_cart(self, product: Product, qty: Decimal = Decimal("1"), barcode: str | None = None, barcode_type: str | None = None) -> None:
        if qty <= 0:
            QMessageBox.warning(self, "จำนวนสินค้าไม่ถูกต้อง", "จำนวนจากการสแกนต้องมากกว่า 0")
            return
        self._cart.append(CartLine(product, qty, barcode, barcode_type))
        self._render_cart()


def run() -> None:
    app = QApplication([]); app.setStyle("Fusion")
    window = PosWindow(); window.showMaximized(); app.exec()
