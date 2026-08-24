from __future__ import annotations

from decimal import Decimal

from .services import CartLine, PosService


def run_ui(service: PosService) -> None:
    try:
        from PySide6.QtCore import Qt
        from PySide6.QtWidgets import (
            QApplication, QDialog, QFormLayout, QHBoxLayout, QLabel, QLineEdit,
            QMainWindow, QMessageBox, QPushButton, QTableWidget, QTableWidgetItem,
            QVBoxLayout, QWidget,
        )
    except ImportError as error:
        raise RuntimeError("ยังไม่ได้ติดตั้ง PySide6: python3 -m pip install -r requirements.txt") from error

    class LoginDialog(QDialog):
        def __init__(self):
            super().__init__()
            self.cashier = None
            self.setWindowTitle("POPSTAR Python POS - เข้าสู่ระบบ")
            form = QFormLayout(self)
            self.code = QLineEdit("POP001")
            self.pin = QLineEdit("1234")
            self.pin.setEchoMode(QLineEdit.Password)
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

    class PosWindow(QMainWindow):
        def __init__(self, cashier):
            super().__init__()
            self.cashier = cashier
            self.shift_id = service.open_shift(1, "PY-TEST-01", int(cashier["id"]), Decimal("0"))
            self.lines: list[CartLine] = []
            self.setWindowTitle(f"POPSTAR Python POS - {cashier['name']}")
            root = QWidget()
            layout = QVBoxLayout(root)
            self.barcode = QLineEdit()
            self.barcode.setPlaceholderText("สแกนบาร์โค้ด แล้วกด Enter")
            self.barcode.returnPressed.connect(self.add_scanned_product)
            self.table = QTableWidget(0, 4)
            self.table.setHorizontalHeaderLabels(["สินค้า", "จำนวน", "ราคา", "รวม"])
            self.total = QLabel("รวม 0.00 บาท | Offline queue: 0")
            self.total.setAlignment(Qt.AlignRight)
            cash = QPushButton("รับเงินสด / ชำระ")
            cash.clicked.connect(self.pay_cash)
            layout.addWidget(QLabel("สถานะ: Offline-first prototype"))
            layout.addWidget(self.barcode)
            layout.addWidget(self.table)
            layout.addWidget(self.total)
            layout.addWidget(cash)
            self.setCentralWidget(root)

        def add_scanned_product(self):
            scanned = self.barcode.text().strip()
            product = service.lookup_barcode(scanned)
            if not product:
                QMessageBox.warning(self, "ไม่พบสินค้า", f"ไม่พบบาร์โค้ด {scanned}")
                return
            price = Decimal(str(product["price"] or 0))
            self.lines.append(CartLine(int(product["id"]), Decimal("1"), price, barcode=scanned, source_barcode=scanned))
            row = self.table.rowCount()
            self.table.insertRow(row)
            for col, value in enumerate([product["name"], "1", f"{price:.2f}", f"{price:.2f}"]):
                self.table.setItem(row, col, QTableWidgetItem(str(value)))
            self.barcode.clear()
            self.refresh_total()

        def refresh_total(self):
            amount = sum((line.qty * line.unit_price for line in self.lines), Decimal("0"))
            self.total.setText(f"รวม {amount:.2f} บาท | Offline queue: {service.pending_sync_count()}")

        def pay_cash(self):
            if not self.lines:
                return
            amount = sum((line.qty * line.unit_price for line in self.lines), Decimal("0"))
            sale_id = service.checkout(
                document_no=f"PYPOS-{self.shift_id}-{service.pending_sync_count() + 1:06d}",
                branch_id=1, terminal_id="PY-TEST-01", shift_id=self.shift_id,
                cashier_id=int(self.cashier["id"]), lines=self.lines, payment_method="cash", paid_amount=amount,
            )
            QMessageBox.information(self, "บันทึกสำเร็จ", f"บันทึกบิล local id {sale_id} และเข้าคิว sync แล้ว")
            self.lines = []
            self.table.setRowCount(0)
            self.refresh_total()

    app = QApplication([])
    login = LoginDialog()
    if login.exec() == QDialog.Accepted:
        window = PosWindow(login.cashier)
        window.resize(900, 600)
        window.show()
        app.exec()
