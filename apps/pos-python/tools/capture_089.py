"""Capture real cashier screens with temporary fixtures and mocked ERP only."""
import os
if os.environ.get('QT_QPA_PLATFORM') != 'offscreen':
    raise RuntimeError('Set QT_QPA_PLATFORM=offscreen')
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
from unittest.mock import patch
from PySide6.QtWidgets import QDialog
from PySide6.QtGui import QPixmap, QPainter, QColor
sys.path.insert(0, str(Path(__file__).resolve().parents[1]/'tests'))
from test_polish_089 import till, sale_fixture
from pos_python.touch_dialogs import ValueDialog, TouchDialog
from pos_python.ui import PALETTE

out=Path(__file__).resolve().parents[3]/'docs/ai/pos-python-shots/089'
out.mkdir(parents=True,exist_ok=True)
with TemporaryDirectory() as folder:
    fixture=till.__wrapped__(Path(folder))
    window,service,online,app=next(fixture)
    window.refresh_runtime_context()
    window.refresh_order()
    def capture(widget,name):
        widget.show();app.processEvents();widget.repaint();app.processEvents()
        assert widget.width()<=1024 and widget.height()<=768, (name,widget.size())
        canvas=QPixmap(1024,768);canvas.fill(QColor(PALETTE['bg']))
        painter=QPainter(canvas)
        painter.drawPixmap((1024-widget.width())//2,(768-widget.height())//2,widget.grab())
        painter.end()
        assert canvas.save(str(out/(name+'.png')))
    try:
        window.scan.setText('ก้าวหน้า');capture(window,'search-clear')
        window.clear_search.click();capture(window,'search-restored')
        sale_id=sale_fixture(service)
        def history_choice(dialog):
            capture(dialog,'void-history');dialog.choice=sale_id;dialog.reject()
            return QDialog.Accepted
        def details(dialog):
            capture(dialog,'void-details');dialog.reject();return QDialog.Rejected
        with patch('pos_python.touch_dialogs.ChoiceDialog.exec',history_choice),patch.object(TouchDialog,'exec',details):
            window.show_history()
        def reason(dialog):
            dialog.entry.setText('คิดสินค้าผิด');capture(dialog,'void-reason');dialog.reject();return QDialog.Accepted
        def manager(dialog):
            capture(dialog,'void-manager');dialog.choice=901;dialog.reject();return QDialog.Accepted
        def pin_dialog(cls,parent,title,subtitle,value='',pin=False):
            dialog=ValueDialog(parent,title,subtitle,value,pin)
            capture(dialog,'void-pin');dialog.reject()
            return object(),True
        def result(parent,title,text):
            from PySide6.QtWidgets import QLabel
            dialog=TouchDialog(parent,title)
            label=QLabel(text);label.setWordWrap(True);dialog.body.addWidget(label);dialog.confirm('ตกลง')
            capture(dialog,'void-result');dialog.reject()
        with patch('pos_python.touch_dialogs.TextDialog.exec',reason),patch('pos_python.touch_dialogs.ChoiceDialog.exec',manager),patch.object(ValueDialog,'get_value',classmethod(pin_dialog)),patch('pos_python.touch_dialogs.TouchMessage.information',result):
            window.receipt_action(sale_id,False)
    finally:
        try: next(fixture)
        except StopIteration: pass
