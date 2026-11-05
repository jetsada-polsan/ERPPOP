"""Exercise the actual nested payment dialog and recoverable till state offline."""
import os
os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')
from decimal import Decimal as D
from unittest.mock import patch

import pytest
pytest.importorskip('PySide6')
from PySide6.QtCore import QEvent
from PySide6.QtWidgets import QApplication, QDialog, QPushButton, QLineEdit

from pos_python.database import connect
from pos_python.order import OrderLine
from pos_python.services import PosService, now
from pos_python.settings_service import SettingsService
from pos_python.ui import run_ui, build_pairing_dialog


def test_touch_payment_hold_recall_and_restart(tmp_path):
    app = QApplication.instance() or QApplication([])
    db = connect(tmp_path / 'ui.db')
    db.execute("INSERT INTO products(id,sku,name,unit_name,price,updated_at) VALUES(1,'UAT','สินค้า','ชิ้น','100',?)", (now(),))
    db.commit()
    window = run_ui(PosService(db), app=app, data_dir=tmp_path)
    try:
        window.showNormal()
        window.resize(1024, 768)
        window.order.add_product(OrderLine(1, 'สินค้า', 'ชิ้น', D(1), D(100)))
        window.order.bill_discount = D(10)
        window.order.adjustment_approved_by = 'ผู้อนุมัติ UAT'
        window.refresh_order()
        with patch("pos_python.touch_dialogs.TextDialog.exec", return_value=QDialog.Accepted):
            window.hold_order()
        assert window.order.bill_discount == 0
        def choose_latest(dialog):
            dialog.choice = ("local", 0)
            return QDialog.Accepted
        with patch("pos_python.touch_dialogs.ChoiceDialog.exec", choose_latest):
            window.recall_order()
        assert window.order.bill_discount == 10
        assert window.order.adjustment_approved_by == 'ผู้อนุมัติ UAT'
        assert window.order.grand_total() == 90
        window.ensure_sale_session = lambda: True
        seen = []
        def inspect_dialog(dialog):
            dialog.show()
            app.processEvents()
            seen.append(dialog)
            assert dialog.width() <= 1024 and dialog.height() <= 768
            for button in dialog.findChildren(QPushButton):
                assert button.height() >= 56
                assert button.fontMetrics().height() >= 18
            dialog.bill_discount.setFocus()
            app.processEvents()
            dialog.keypress('ล้าง')
            dialog.keypress('2')
            dialog.keypress('5')
            assert window.order.bill_discount == 25
            assert window.order.grand_total() == 75
            assert dialog.tendered() == 75
            dialog.amount.setText('100')
            assert '25.00' in dialog.change.text()
            dialog.reject()
            return QDialog.Rejected
        with patch.object(QDialog, 'exec', inspect_dialog):
            window.pay()
        assert len(seen) == 1
        saved = SettingsService(db).get_device_setting('current_order')
        window._allow_close = True; window.close()
        restarted = run_ui(PosService(db), app=app, data_dir=tmp_path)
        assert restarted.order.bill_discount == 25
        assert restarted.order.grand_total() == 75
        restarted._allow_close = True; restarted.close()
        restarted.deleteLater()
    finally:
        window._allow_close = True; window.close()
        window.deleteLater()
        app.sendPostedEvents(None, QEvent.DeferredDelete)
        db.close()


def test_pairing_starts_blank_and_replaces_clipboard_text(tmp_path):
    app = QApplication.instance() or QApplication([])
    previous = app.clipboard().text()
    dialog = build_pairing_dialog(tmp_path, app)
    try:
        field = dialog.findChild(QLineEdit, 'pairingToken')
        assert field.text() == ''
        field.setText('ข้อความเดิม')
        app.clipboard().setText('ข้อความตัวอย่าง')
        paste = next(b for b in dialog.findChildren(QPushButton) if b.text() == 'วาง')
        paste.click()
        assert field.text() == 'ข้อความตัวอย่าง'
        show = next(b for b in dialog.findChildren(QPushButton) if b.text() == 'แสดง')
        show.click()
        assert field.echoMode() == QLineEdit.Normal
        another = build_pairing_dialog(tmp_path, app)
        assert another.findChild(QLineEdit, 'pairingToken').text() == ''
        another.close()
        assert not (tmp_path / 'device.json').exists()
    finally:
        dialog.close()
        app.clipboard().setText(previous)
