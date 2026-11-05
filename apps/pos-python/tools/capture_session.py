"""Offline session previews. Run with QT_QPA_PLATFORM=offscreen; creates no credentials."""
import os
if os.environ.get('QT_QPA_PLATFORM') != 'offscreen':
    raise RuntimeError('Set QT_QPA_PLATFORM=offscreen')
from pathlib import Path
from tempfile import TemporaryDirectory
from decimal import Decimal as D
from unittest.mock import patch
from PySide6.QtWidgets import QApplication, QDialog
from PySide6.QtCore import Qt, QEvent
from PySide6.QtGui import QPixmap, QPainter, QColor
from pos_python.database import connect
from pos_python.services import PosService, now
from pos_python.order import OrderLine
from pos_python.ui import run_ui, PALETTE, build_pairing_dialog
from pos_python.touch_dialogs import ValueDialog

app=QApplication.instance() or QApplication([])
out=Path(__file__).resolve().parents[3]/'docs/ai/pos-python-shots/session'
out.mkdir(parents=True,exist_ok=True)

def capture(widget,name):
    widget.show();app.processEvents()
    assert widget.width() <= 1024 and widget.height() <= 768, (name,widget.size())
    canvas=QPixmap(1024,768);canvas.fill(QColor(PALETTE['bg']))
    painter=QPainter(canvas)
    painter.drawPixmap((1024-widget.width())//2,(768-widget.height())//2,widget.grab())
    painter.end()
    assert canvas.save(str(out/(name+'.png')))

with TemporaryDirectory() as folder:
    db=connect(Path(folder)/'capture.db')
    db.execute("INSERT INTO local_cashiers(id,code,name,pin_hash,synced_at) VALUES(1,'PREVIEW','พนักงานตัวอย่าง','',?)",(now(),))
    for i,name,price in [(1,'หมูสามชั้นสไลซ์','189'),(2,'น้ำจิ้มหมูกระทะ','45'),(3,'น้ำดื่ม','7')]:
        db.execute('INSERT INTO products(id,sku,name,unit_name,price,updated_at) VALUES(?,?,?,?,?,?)',(i,f'P{i}',name,'ชิ้น',price,now()))
    db.commit()
    service=PosService(db);w=run_ui(service,app=app,data_dir=Path(folder))
    w.showNormal();w.resize(1024,768)
    capture(w,'lock')
    for name,dialog in [('pin',ValueDialog(w,'เข้างาน','ใส่ PIN คนขาย',pin=True)),('opening',w.dialog_types['opening'](w)),('pairing',build_pairing_dialog(Path(folder),app))]:
        capture(dialog,name);dialog.reject()
    w.cashier=db.execute('SELECT * FROM local_cashiers WHERE id=1').fetchone()
    w.shift_id=service.open_shift(1,'PY-TEST-01',1,D(500));w.opening_cash=D(500)
    w.locked=False;w.pages.setCurrentIndex(0)
    for i,name,price in [(1,'หมูสามชั้นสไลซ์','189'),(2,'น้ำจิ้มหมูกระทะ','45')]:
        w.order.add_product(OrderLine(i,name,'ชิ้น',D(2),D(price)))
    w.refresh_order();capture(w,'sales')
    for name,dialog in [('close-app',w.build_close_dialog()),('sign-off',w.dialog_types['closing'](w,D(500)))]:
        capture(dialog,name);dialog.reject()
    service.close_shift(shift_id=w.shift_id,counted_cash=D(500))
    def z_capture(dialog):
        capture(dialog,'z-report');dialog.reject();return QDialog.Rejected
    with patch('pos_python.touch_dialogs.TouchDialog.exec',z_capture):
        w.show_z_report()
    w._allow_close=True;w.close();w.deleteLater()
    app.sendPostedEvents(None,QEvent.DeferredDelete);db.close()
print(out)
