"""Offline float previews, no credentials or network. Requires offscreen Qt."""
import os
if os.environ.get('QT_QPA_PLATFORM') != 'offscreen':
    raise RuntimeError('Set QT_QPA_PLATFORM=offscreen')
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import Mock, patch
from decimal import Decimal as D
from PySide6.QtWidgets import QApplication, QDialog, QScrollArea
from PySide6.QtCore import QEvent
from PySide6.QtGui import QPixmap, QPainter, QColor
from pos_python.database import connect
from pos_python.services import PosService, now
from pos_python.settings_service import SettingsService
from pos_python.shift_float import FloatClose
from pos_python.ui import run_ui, PALETTE
from pos_python.touch_dialogs import TouchDialog

app=QApplication.instance() or QApplication([])
out=Path(__file__).resolve().parents[3]/'docs/ai/pos-python-shots/shift-float'
out.mkdir(parents=True,exist_ok=True)
policy=dict(default_float='2000',carried_float='2000',blind_count=True,shift=dict(id=91))
shift=dict(id=91,opening_cash='2000',carried_float='2000',opening_variance='0',opening_note='',
    cash_sales='13432.75',transfer_sales='0',card_sales='0',cheque_sales='0',cash_in='0',cash_drops='0',cash_payouts='0',
    expected_cash='15432.75',counted_cash='15432.75',cash_difference='0',float_kept='2000',deposit_amount='13432.75',
    deposit_reference='BAG-001',discount_total='0',void_count=0,return_count=0)

def capture(widget,name):
    widget.show();app.processEvents()
    assert widget.width() <= 1024 and widget.height() <= 768, (name,widget.size())
    canvas=QPixmap(1024,768);canvas.fill(QColor(PALETTE['bg']))
    painter=QPainter(canvas)
    painter.drawPixmap((1024-widget.width())//2,(768-widget.height())//2,widget.grab());painter.end()
    assert canvas.save(str(out/(name+'.png')))

with TemporaryDirectory() as folder:
    db=connect(Path(folder)/'preview.db')
    db.execute("INSERT INTO local_cashiers(id,code,name,pin_hash,synced_at) VALUES(1,'PREVIEW','พนักงานตัวอย่าง','',?)",(now(),));db.commit()
    service=PosService(db);w=run_ui(service,app=app,data_dir=Path(folder))
    w.showNormal();w.resize(1024,768);w.shift_policy=policy
    opening=w.dialog_types['opening'](w);capture(opening,'opening');opening.reject()
    w.shift_id=service.open_shift(1,'TEST',1,D(2000))
    api=Mock();api.post.return_value=dict(preview=True,shift=shift)
    closing=w.dialog_types['float_closing'](w,FloatClose(api,policy,91),policy)
    capture(closing,'closing-blind')
    closing.amount.setText('15432.75');closing.reference.setText('BAG-001');closing.advance()
    capture(closing,'closing-preview')
    closing.advance();capture(closing,'closing-split')
    closing.advance();capture(closing,'closing-confirm');closing.reject()
    def grab_drop(dialog):
        capture(dialog,'mid-shift-drop');dialog.reject();return QDialog.Rejected
    with patch.object(TouchDialog,'exec',grab_drop):w.record_cash_movement(drop_only=True)
    service.close_shift(shift_id=w.shift_id,counted_cash=D('15432.75'))
    db.execute('UPDATE shifts SET server_id=91 WHERE id=?',(w.shift_id,));db.commit()
    SettingsService(db).set_device_setting('last_z_report',shift)
    def grab_z(dialog):
        capture(dialog,'z-report')
        scroll=dialog.findChild(QScrollArea)
        scroll.verticalScrollBar().setValue(scroll.verticalScrollBar().maximum())
        capture(dialog,'z-report-signatures')
        dialog.reject();return QDialog.Rejected
    with patch.object(TouchDialog,'exec',grab_z):w.show_z_report()
    w._allow_close=True;w.close();w.deleteLater();app.sendPostedEvents(None,QEvent.DeferredDelete);db.close()
print(out)
