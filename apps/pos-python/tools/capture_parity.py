"""Offline-only 1024×768 UAT previews; run with QT_QPA_PLATFORM=offscreen."""
from pathlib import Path
from tempfile import TemporaryDirectory
from decimal import Decimal as D
from PySide6.QtWidgets import QApplication, QDialog, QPushButton, QLineEdit
from PySide6.QtCore import QTimer
from PySide6.QtGui import QPixmap, QPainter, QColor
from pos_python.database import connect
from pos_python.services import PosService, now
from pos_python.order import OrderLine
from pos_python.promotions import replace_promotions
from pos_python.ui import run_ui, build_pairing_dialog, PALETTE
from pos_python.touch_dialogs import ValueDialog, TextDialog, ChoiceDialog, TouchDialog
from unittest.mock import patch

app = QApplication([])
shots = Path(__file__).resolve().parents[3] / 'docs/ai/pos-python-shots'
shots.mkdir(parents=True, exist_ok=True)

def save_screen(dialog, filename):
    canvas = QPixmap(1024, 768)
    canvas.fill(QColor(PALETTE['text'] if shots.name == 'popups' else PALETTE['bg']))
    painter = QPainter(canvas)
    painter.drawPixmap((1024 - dialog.width()) // 2, (768 - dialog.height()) // 2, dialog.grab())
    painter.end()
    assert canvas.save(str(shots / filename))
with TemporaryDirectory() as folder:
    db = connect(Path(folder) / 'preview.db')
    for i, name, price in [(1, 'น้ำปลา', '40'), (2, 'น้ำดื่มของแถม', '15')]:
        db.execute('INSERT INTO products(id,server_id,sku,name,unit_name,price,updated_at) VALUES(?,?,?,?,?,?,?)', (i, i, str(i), name, 'ขวด', price, now()))
    replace_promotions(db, [dict(id=1, name='3 ขวด 100', promo_type='bundle_price', product_id=1, min_qty=3, bundle_price=100), dict(id=2, name='ซื้อครบแถมน้ำ', promo_type='free_item', product_id=1, min_qty=3, free_product_id=2, free_qty=1)])
    db.commit()
    window = run_ui(PosService(db), app=app, data_dir=Path(folder))
    window.showNormal()
    window.resize(1024, 768)
    window.ensure_sale_session = lambda: True
    window.order.add_product(OrderLine(1, 'น้ำปลา', 'ขวด', D(3), D(40)))
    window.order.bill_discount = D(10)
    window.refresh_order()
    app.processEvents()
    window.grab().save(str(shots / 'cart-1024x768.png'))
    results = []
    def capture_payment():
        dialog = app.activeModalWidget()
        dialog.move(22, 30)
        app.processEvents()
        results.append((dialog.width(), dialog.height()))
        assert dialog.width() <= 1024 and dialog.height() <= 768, results
        for button in dialog.findChildren(QPushButton):
            assert button.height() >= 56, (button.text(), button.size())
        save_screen(dialog, 'payment-1024x768.png')
        field = dialog.findChild(QLineEdit, 'billDiscount')
        field.setText('15')
        assert window.order.grand_total() == D(85)
        dialog.reject()
    QTimer.singleShot(100, capture_payment)
    window.pay()
    pairing = build_pairing_dialog(Path(folder), app)
    pairing.show()
    app.processEvents()
    assert pairing.findChild(QLineEdit, 'pairingToken').text() == ''
    save_screen(pairing, 'pairing-1024x768.png')
    pairing.close()
    shots = shots / 'popups'
    shots.mkdir(parents=True, exist_ok=True)
    def capture(dialog, name):
        dialog.show()
        app.processEvents()
        assert dialog.width() <= 1024 and dialog.height() <= 768, (name, dialog.size())
        save_screen(dialog, name + '-1024x768.png')
        dialog.close()
    db.execute("INSERT INTO local_cashiers(id,code,name,pin_hash,synced_at) VALUES(1,'PREVIEW','ตัวอย่างคนขาย','',?)", (now(),))
    db.commit()
    shift_id = window.shift_id = PosService(db).open_shift(1, 'PREVIEW', 1, D(1000))
    for name in ('cashier', 'changePin', 'void', 'return'):
        capture(ValueDialog(window, {'cashier':'เริ่มขาย','changePin':'เปลี่ยน PIN','void':'ยกเลิกบิล','return':'รับคืนสินค้า'}[name], 'PIN คนขาย' if name=='cashier' else 'PIN หัวหน้า' if name in ('void','return') else 'เลือก PIN ใหม่ด้วยตนเอง', pin=True), name)
    capture(window.dialog_types['seller'](), 'selectCashier')
    capture(window.dialog_types['opening'](window), 'shift')
    capture(window.dialog_types['closing'](window, D(1000)), 'closeShift')
    shift_row = db.execute('SELECT * FROM shifts WHERE id = ?', (shift_id,)).fetchone()
    capture(window.dialog_types['existing'](window, shift_row), 'existingShift')
    capture(ValueDialog(window, 'นำส่งเงินระหว่างกะ', 'จำนวนเงิน (บาท)', '500'), 'cash-drop-amount')
    details = TextDialog(window, 'นำส่งเงินระหว่างกะ', 'เหตุผล', 'นำส่งเงินระหว่างกะ')
    reference = QLineEdit('ซอง 001')
    reference.setPlaceholderText('เลขอ้างอิง / ซองเงิน')
    details.body.insertWidget(2, reference)
    capture(details, 'cash-drop-reference')
    capture(ValueDialog(window, 'น้ำหนักสินค้า', 'ราคาต่อกิโลกรัม 120.00 บาท', '0.350'), 'weight')
    capture(TextDialog(window, 'พักบิล', 'ตั้งชื่อบิลเพื่อเรียกกลับ', 'ลูกค้าหน้าร้าน'), 'holdBill')
    capture(ChoiceDialog(window, 'บิลพัก', [('ลูกค้าหน้าร้าน · 85.00 บาท', 0)]), 'heldBills')
    capture(ChoiceDialog(window, 'ประวัติบิล', [('ตัวอย่างบิล · 85.00 บาท · ส่งแล้ว', 1)]), 'history')
    capture(window.dialog_types['payment'](window, window.order), 'payment')
    def capture_close(dialog):
        capture(dialog, 'closeApp')
        return QDialog.Rejected
    event = type('ClosePreview', (), {'spontaneous': lambda self: True, 'ignore': lambda self: None})()
    with patch.object(TouchDialog, 'exec', capture_close):
        window.closeEvent(event)
    PosService(db).close_shift(shift_id=shift_id, counted_cash=D(1000))
    with patch.object(TouchDialog, 'exec', lambda dialog: capture(dialog, 'z-report')):
        window.show_z_report()
    window.close()
    db.close()
print('Preview geometry:', results)
