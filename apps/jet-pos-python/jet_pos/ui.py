from __future__ import annotations

from decimal import Decimal

from PySide6.QtCore import Qt
from PySide6.QtGui import QColor
from PySide6.QtWidgets import QApplication, QFrame, QGridLayout, QHBoxLayout, QLabel, QLineEdit, QMainWindow, QPushButton, QScrollArea, QVBoxLayout, QWidget

from .models import Product


SAMPLE_PRODUCTS = (
    Product(1, "301261", "100พลัส เรกูลาร์ 370 มล.", Decimal("20.00")),
    Product(2, "208078", "3A ฝาโดม 90 เจาะรู", Decimal("20.00")),
    Product(3, "208270", "3A ฝาโดม 95 ทรงสูง", Decimal("36.00")),
    Product(4, "205012", "A88 ถังหิ้วเล็ก แพ็ค 150g", Decimal("20.00")),
    Product(5, "203053", "ANATOLI เส้นสปาเก็ตตี้", Decimal("39.00")),
    Product(6, "104011", "BB-B ถังน้ำอัดลม", Decimal("80.00")),
)


class PosWindow(QMainWindow):
    def __init__(self) -> None:
        super().__init__()
        self.setWindowTitle("JET POS Python — UAT")
        self.resize(1440, 860)
        self._cart: list[Product] = []
        self._total = QLabel("฿0.00")
        self._items = QLabel("0 รายการ · 0 ชิ้น")
        self._cart_body = QVBoxLayout()
        self._build()

    def _build(self) -> None:
        root = QWidget()
        root.setStyleSheet("background:#eef3f7;color:#16344b;font-family:'Segoe UI';")
        layout = QVBoxLayout(root)
        layout.setContentsMargins(0, 0, 0, 0)
        layout.setSpacing(0)
        layout.addWidget(self._header())
        content = QHBoxLayout()
        content.setContentsMargins(16, 16, 16, 16)
        content.setSpacing(16)
        content.addWidget(self._catalog(), 2)
        content.addWidget(self._basket(), 1)
        layout.addLayout(content, 1)
        self.setCentralWidget(root)

    def _header(self) -> QWidget:
        bar = QFrame()
        bar.setFixedHeight(88)
        bar.setStyleSheet("background:#075b86;color:white;")
        row = QHBoxLayout(bar)
        row.setContentsMargins(26, 12, 26, 12)
        brand = QLabel("MPM  |  JET POS\nPOINT OF SALE")
        brand.setStyleSheet("font-size:18px;font-weight:800;")
        row.addWidget(brand)
        context = QLabel("สาขา / เครื่อง\nสาขาอำนาจเจริญ · POS-B001-02")
        context.setStyleSheet("padding-left:24px;border-left:1px solid #62a9ca;font-weight:700;")
        row.addWidget(context)
        row.addStretch()
        status = QLabel("● กำลังซิงก์")
        status.setStyleSheet("padding:10px 18px;border:1px solid #55c9ad;border-radius:8px;background:#08745f;font-weight:800;")
        row.addWidget(status)
        row.addWidget(QPushButton("⚙ ตั้งค่า"))
        return bar

    def _catalog(self) -> QWidget:
        panel = QFrame()
        panel.setStyleSheet("QFrame{background:white;border:1px solid #d5e1e8;border-radius:10px;} QLabel{border:0;}")
        box = QVBoxLayout(panel)
        title = QLabel("รายการสินค้า\nเลือกหรือสแกนเพื่อขาย")
        title.setStyleSheet("font-size:16px;font-weight:800;border:0;")
        box.addWidget(title)
        search = QLineEdit()
        search.setPlaceholderText("สแกนบาร์โค้ด หรือค้นหาชื่อสินค้า")
        search.setStyleSheet("padding:15px;font-size:16px;border:1px solid #cbdce6;border-radius:8px;")
        search.textChanged.connect(self._filter_products)
        box.addWidget(search)
        quick = QLabel("ขายบ่อยบนเครื่องนี้    เรียง 30 วันล่าสุด")
        quick.setStyleSheet("background:#fff4f4;color:#15668f;padding:12px;border-radius:8px;font-weight:700;")
        box.addWidget(quick)
        self._grid = QGridLayout()
        self._grid.setSpacing(10)
        grid_widget = QWidget()
        grid_widget.setLayout(self._grid)
        scroll = QScrollArea()
        scroll.setWidgetResizable(True)
        scroll.setWidget(grid_widget)
        scroll.setStyleSheet("border:0;")
        box.addWidget(scroll, 1)
        self._render_products(SAMPLE_PRODUCTS)
        return panel

    def _basket(self) -> QWidget:
        panel = QFrame()
        panel.setStyleSheet("QFrame{background:white;border:1px solid #d5e1e8;border-radius:10px;} QLabel{border:0;}")
        box = QVBoxLayout(panel)
        header = QHBoxLayout()
        heading = QLabel("บิลปัจจุบัน\nรายการขาย")
        heading.setStyleSheet("font-size:17px;font-weight:800;color:#075b86;")
        header.addWidget(heading)
        header.addStretch()
        header.addWidget(self._items)
        box.addLayout(header)
        cart = QWidget()
        cart.setLayout(self._cart_body)
        cart_scroll = QScrollArea()
        cart_scroll.setWidgetResizable(True)
        cart_scroll.setWidget(cart)
        cart_scroll.setStyleSheet("border:0;")
        box.addWidget(cart_scroll, 1)
        self._empty_cart()
        total_box = QFrame()
        total_box.setStyleSheet("background:#075b86;border-radius:8px;color:white;")
        total_layout = QVBoxLayout(total_box)
        total_layout.addWidget(QLabel("ยอดรวมสุทธิ"))
        self._total.setAlignment(Qt.AlignmentFlag.AlignRight)
        self._total.setStyleSheet("font-size:38px;font-weight:900;color:white;")
        total_layout.addWidget(self._total)
        box.addWidget(total_box)
        pay = QPushButton("รับชำระเงิน  ›")
        pay.setEnabled(False)
        pay.setStyleSheet("QPushButton{padding:18px;background:#aebfca;color:white;border:0;border-radius:8px;font-size:18px;font-weight:800;}")
        box.addWidget(pay)
        return panel

    def _render_products(self, products: tuple[Product, ...]) -> None:
        while self._grid.count():
            child = self._grid.takeAt(0)
            if child.widget():
                child.widget().deleteLater()
        for index, product in enumerate(products):
            button = QPushButton(f"{product.sku_code}\n{product.name_th}\n\n฿{product.pos_price:.2f}")
            button.setMinimumHeight(146)
            button.setStyleSheet("QPushButton{text-align:left;padding:14px;background:#fff;border:1px solid #d7e4eb;border-radius:8px;font-size:14px;font-weight:700;color:#1b4059;} QPushButton:hover{border:2px solid #1596cb;background:#effbff;}")
            button.clicked.connect(lambda _, item=product: self._add_to_cart(item))
            self._grid.addWidget(button, index // 3, index % 3)

    def _filter_products(self, query: str) -> None:
        search = query.strip().lower()
        self._render_products(tuple(item for item in SAMPLE_PRODUCTS if search in item.sku_code.lower() or search in item.name_th.lower()))

    def _empty_cart(self) -> None:
        self._cart_body.addStretch()
        label = QLabel("🛒\nยังไม่มีรายการขาย\nสแกนสินค้า หรือเลือกจากด้านซ้าย")
        label.setAlignment(Qt.AlignmentFlag.AlignCenter)
        label.setStyleSheet("color:#8ba0ad;font-size:16px;")
        self._cart_body.addWidget(label)
        self._cart_body.addStretch()

    def _add_to_cart(self, product: Product) -> None:
        self._cart.append(product)
        while self._cart_body.count():
            child = self._cart_body.takeAt(0)
            if child.widget():
                child.widget().deleteLater()
        for item in self._cart:
            self._cart_body.addWidget(QLabel(f"{item.sku_code}  {item.name_th}                         ฿{item.pos_price:.2f}"))
        self._cart_body.addStretch()
        total = sum((item.pos_price for item in self._cart), Decimal("0"))
        self._total.setText(f"฿{total:.2f}")
        self._items.setText(f"{len(self._cart)} รายการ · {len(self._cart)} ชิ้น")


def run() -> None:
    app = QApplication([])
    app.setStyle("Fusion")
    window = PosWindow()
    window.showMaximized()
    app.exec()

