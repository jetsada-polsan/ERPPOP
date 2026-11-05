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

app = QApplication([])
shots = Path(__file__).resolve().parents[3] / 'docs/ai/pos-python-shots'
shots.mkdir(parents=True, exist_ok=True)

def save_screen(dialog, filename):
    canvas = QPixmap(1024, 768)
    canvas.fill(QColor(PALETTE['bg']))
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
    window.close()
    db.close()
print('Preview geometry:', results)
