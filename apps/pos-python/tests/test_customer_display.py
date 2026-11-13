"""จอลูกค้า: เปิดเองเมื่อมีจอที่ 2 และแสดงเฉพาะรายการ/ยอดที่ลูกค้าควรเห็น (เจ้าของ 13 พ.ย.)"""
import os
from decimal import Decimal as D

import pytest

from pos_python.customer_display import order_rows, pick_customer_screen
from pos_python.order import Order, OrderLine
from pos_python.promotions import QtyPromotion


def test_customer_screen_is_the_second_monitor_only():
    assert pick_customer_screen(["main"]) is None
    assert pick_customer_screen(["main", "customer"]) == "customer"


def test_rows_show_net_price_after_mix_promotion_and_free_gifts():
    order = Order(lines=[OrderLine(1, "ซาลาเปาไส้ครีม", "แพ็ค", D("1"), D("35")),
                         OrderLine(2, "ซาลาเปาหมูแดง", "แพ็ค", D("1"), D("35")),
                         OrderLine(3, "ไก่แดดเดียว", "แพ็ค", D("1"), D("49"))],
                  promotions=[QtyPromotion(product_id=1, promo_type="mix_bundle", min_qty=D(3), bundle_price=D(100),
                                           product_ids=(1, 2, 3), name="3 แพ็ค 100")])
    rows = order_rows(order)
    assert [r[0] for r in rows] == ["ซาลาเปาไส้ครีม", "ซาลาเปาหมูแดง", "ไก่แดดเดียว"]
    assert sum(D(r[2].replace(",", "")) for r in rows) == order.grand_total() == 100


def test_window_renders_offscreen_without_shop_figures():
    os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")
    widgets = pytest.importorskip("PySide6.QtWidgets")
    from PySide6.QtCore import Qt
    from pos_python.customer_display import build_customer_display
    app = widgets.QApplication.instance() or widgets.QApplication([])
    Display = build_customer_display(widgets.QWidget, widgets.QLabel, widgets.QVBoxLayout, widgets.QHBoxLayout,
                                     widgets.QTableWidget, widgets.QTableWidgetItem, widgets.QHeaderView, Qt, "POPSTAR")
    screen = Display()
    screen.update_order(Order(lines=[OrderLine(1, "น้ำหวาน", "ขวด", D("2"), D("59"))]))
    assert screen.total.text() == "฿118.00"
    assert screen.table.rowCount() == 1
    screen.show_paid(D("82"))
    assert "82.00" in screen.message.text()
    texts = " ".join(w.text() for w in screen.findChildren(widgets.QLabel))
    assert "บิลวันนี้" not in texts and "ยอดขาย" not in texts
    app.processEvents()
