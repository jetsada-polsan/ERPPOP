"""Payment previews using local temporary data, no network or credentials."""
import os
if os.environ.get('QT_QPA_PLATFORM') != 'offscreen':
    raise RuntimeError('Set QT_QPA_PLATFORM=offscreen')
from pathlib import Path
from tempfile import TemporaryDirectory
from decimal import Decimal as D
from PySide6.QtWidgets import QApplication
from PySide6.QtGui import QPixmap, QPainter, QColor
from pos_python.database import connect
from pos_python.services import PosService, now
from pos_python.order import OrderLine
from pos_python.ui import run_ui, PALETTE
from pos_python.payments import LABELS

app=QApplication.instance() or QApplication([])
out=Path(__file__).resolve().parents[3]/'docs/ai/pos-python-shots/payments'
out.mkdir(parents=True,exist_ok=True)
with TemporaryDirectory() as folder:
    db=connect(Path(folder)/'preview.db')
    window=run_ui(PosService(db),app=app,data_dir=Path(folder))
    window.showNormal();window.resize(1024,768)
    window.order.add_product(OrderLine(1,'สินค้าตัวอย่าง','ชิ้น',D(1),D('100.05')))
    for method in LABELS:
        dialog=window.dialog_types['payment'](window,window.order)
        dialog.set_payment_method(method)
        dialog.payment_reference.setText('UAT-REF-001')
        dialog.transfer_account_last4.setText('0042')
        if method=='mixed': dialog.amount.setText('30.02')
        dialog.show();app.processEvents();dialog.repaint();app.processEvents()
        assert dialog.width()<=1024 and dialog.height()<=768
        canvas=QPixmap(1024,768);canvas.fill(QColor(PALETTE['bg']))
        painter=QPainter(canvas)
        painter.drawPixmap((1024-dialog.width())//2,(768-dialog.height())//2,dialog.grab());painter.end()
        assert canvas.save(str(out/(method+'.png')))
        dialog.reject()
    window._allow_close=True;window.close();db.close()
