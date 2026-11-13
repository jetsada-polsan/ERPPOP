"""จอลูกค้า (จอที่ 2) — เจ้าของ 13 พ.ย.: เปิดเครื่องแล้วจอลูกค้าต้องขึ้นเอง แคชเชียร์ไม่ต้องตั้งค่า

แสดงเฉพาะสิ่งที่ลูกค้าควรเห็น: รายการที่ซื้อ ส่วนลด ยอดสุทธิ เงินรับ/เงินทอน
ไม่แสดงยอดขายวันนี้ จำนวนบิล หรือข้อมูลภายในร้าน
"""
from __future__ import annotations

from decimal import Decimal


def pick_customer_screen(screens):
    """จอลูกค้า = จอที่ไม่ใช่จอหลัก (จอแรกที่ไม่ใช่ primary) ถ้ามีจอเดียวคืน None"""
    if len(screens) < 2:
        return None
    return screens[1]


def order_rows(order) -> list[tuple[str, str, str]]:
    """แถวที่ลูกค้าเห็น: (ชื่อสินค้า, จำนวน, ยอดหลังโปร) รวมของแถมที่ 0 บาท"""
    promo = order.promotion_by_line()
    rows = []
    for index, line in enumerate(order.lines):
        qty = f"{line.qty:,.3f}".rstrip("0").rstrip(".")
        rows.append((line.name, f"{qty} {line.unit_name}", f"{line.total - promo.get(index, Decimal('0')):,.2f}"))
    for gift in order.free_items():
        rows.append((f"ของแถม {gift.name}", format(gift.qty.normalize(), "f"), "0.00"))
    return rows


def build_customer_display(QWidget, QLabel, QVBoxLayout, QHBoxLayout, QTableWidget, QTableWidgetItem, QHeaderView, Qt, store_name: str):
    class CustomerDisplay(QWidget):
        def __init__(self):
            super().__init__(None, Qt.Window | Qt.FramelessWindowHint)
            self.setWindowTitle("จอลูกค้า")
            self.setStyleSheet("background:#0b2340; color:#ffffff;")
            root = QVBoxLayout(self)
            root.setContentsMargins(32, 24, 32, 24)
            root.setSpacing(16)
            title = QLabel(store_name or "ยินดีต้อนรับ")
            title.setStyleSheet("font-size:34px; font-weight:800; color:#7dd3fc;")
            root.addWidget(title)
            self.table = QTableWidget(0, 3)
            self.table.setHorizontalHeaderLabels(["รายการ", "จำนวน", "ราคา"])
            self.table.verticalHeader().hide()
            self.table.setShowGrid(False)
            self.table.setFocusPolicy(Qt.NoFocus)
            self.table.setSelectionMode(QTableWidget.NoSelection)
            self.table.setEditTriggers(QTableWidget.NoEditTriggers)
            header = self.table.horizontalHeader()
            header.setSectionResizeMode(0, QHeaderView.Stretch)
            header.setSectionResizeMode(1, QHeaderView.ResizeToContents)
            header.setSectionResizeMode(2, QHeaderView.ResizeToContents)
            self.table.setStyleSheet(
                "QTableWidget { background:#0f2d52; border:none; font-size:24px; color:#fff; } "
                "QHeaderView::section { background:#0b2340; color:#93c5fd; font-size:18px; border:none; padding:6px; }"
            )
            root.addWidget(self.table, 1)
            self.discount = QLabel("")
            self.discount.setStyleSheet("font-size:24px; color:#fcd34d;")
            root.addWidget(self.discount)
            bottom = QHBoxLayout()
            caption = QLabel("ยอดชำระ")
            caption.setStyleSheet("font-size:30px; font-weight:700;")
            self.total = QLabel("฿0.00")
            self.total.setAlignment(Qt.AlignRight | Qt.AlignVCenter)
            self.total.setStyleSheet("font-size:72px; font-weight:900; color:#34f5c5;")
            bottom.addWidget(caption)
            bottom.addWidget(self.total, 1)
            root.addLayout(bottom)
            self.message = QLabel("")
            self.message.setAlignment(Qt.AlignCenter)
            self.message.setStyleSheet("font-size:32px; font-weight:800; color:#fde68a;")
            root.addWidget(self.message)

        def update_order(self, order) -> None:
            rows = order_rows(order)
            self.table.setRowCount(len(rows))
            for r, (name, qty, amount) in enumerate(rows):
                for c, text in enumerate((name, qty, amount)):
                    item = QTableWidgetItem(text)
                    if c:
                        item.setTextAlignment(Qt.AlignRight | Qt.AlignVCenter)
                    self.table.setItem(r, c, item)
                self.table.setRowHeight(r, 44)
            if rows:
                self.table.scrollToBottom()
            discount = order.discount_total()
            self.discount.setText(f"ส่วนลด ฿{discount:,.2f}" if discount > 0 else "")
            self.total.setText(f"฿{order.grand_total():,.2f}")
            if rows:
                self.message.setText("")

        def show_paid(self, change: Decimal | None) -> None:
            self.message.setText(f"เงินทอน ฿{change:,.2f} · ขอบคุณค่ะ" if change and change > 0 else "ขอบคุณที่อุดหนุนค่ะ")

    return CustomerDisplay
