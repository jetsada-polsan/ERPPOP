"""Session lifecycle against a real temporary SQLite database; no credentials provisioned."""
from decimal import Decimal as D
from pathlib import Path
from unittest.mock import patch
import pytest
from PySide6.QtCore import Qt, QEvent
from PySide6.QtGui import QCloseEvent
from PySide6.QtWidgets import QApplication, QDialog, QPushButton
from PySide6.QtTest import QTest
from pos_python.database import connect
from pos_python.services import PosService, now
from pos_python.order import OrderLine
from pos_python.ui import run_ui
from pos_python.touch_dialogs import TouchDialog, TouchMessage, ValueDialog

APP = QApplication.instance() or QApplication([])

@pytest.fixture
def scene(tmp_path):
    db = connect(tmp_path / 'session.db')
    db.execute("INSERT INTO local_cashiers(id,code,name,pin_hash,synced_at) VALUES(1,'SESSION','คนขายทดสอบ','',?)", (now(),))
    db.execute("INSERT INTO products(id,sku,name,unit_name,price,updated_at) VALUES(1,'ITEM','สินค้า','ชิ้น','10',?)", (now(),))
    db.commit()
    service = PosService(db)
    w = run_ui(service, app=APP, data_dir=tmp_path)
    w.showNormal(); w.resize(1024,768); APP.processEvents()
    yield db, service, w
    w._allow_close = True; w.close(); w.deleteLater()
    APP.sendPostedEvents(None, QEvent.DeferredDelete)
    db.close()


def enter(w, db):
    cashier = db.execute('SELECT * FROM local_cashiers WHERE id=1').fetchone()
    def open_shift(dialog):
        dialog.amount.setText('500')
        dialog.accept_value()
        return dialog.result()
    with patch.object(w, 'pin_login', return_value=cashier), patch.object(w.dialog_types['opening'], 'exec', open_shift):
        w.start_button.click()


def test_enter_pin_then_open_shift_and_resume_without_prompt(scene):
    db, service, w = scene
    assert w.locked and w.pages.currentWidget() is w.lock_page
    enter(w, db)
    assert not w.locked and w.shift_id and w.cashier['id'] == 1
    assert w.opening_cash == D(500)
    shift = w.shift_id
    w.order.add_product(OrderLine(1,'สินค้า','ชิ้น',D(9),D(10)))
    w.refresh_order()
    w.auth_button.click()
    assert w.locked and w.cashier is None
    assert db.execute('SELECT status FROM shifts WHERE id=?',(shift,)).fetchone()[0] == 'open'
    assert w.order.grand_total() == 90
    with patch.object(w, 'pin_login', return_value=db.execute('SELECT * FROM local_cashiers WHERE id=1').fetchone()), patch.object(w.dialog_types['opening'], 'exec', side_effect=AssertionError('must resume')):
        w.start_work()
    assert w.shift_id == shift and not w.locked
    assert db.execute('SELECT count(*) FROM shifts').fetchone()[0] == 1


def test_cancel_pin_or_opening_keeps_locked(scene):
    db, service, w = scene
    with patch.object(ValueDialog,'get_value',return_value=('',False)):
        w.start_work()
    assert w.locked and w.shift_id is None
    with patch.object(w,'pin_login',return_value=db.execute('SELECT * FROM local_cashiers').fetchone()), patch.object(w.dialog_types['opening'],'exec',lambda dialog: QDialog.Rejected):
        w.start_work()
    assert w.locked and not db.execute('SELECT 1 FROM shifts').fetchone()


def test_signoff_closes_shift_prints_z_and_locks(scene):
    db, service, w = scene
    enter(w, db)
    shift = w.shift_id
    def count(dialog):
        dialog.amount.setText('500');dialog.accept()
        return dialog.result()
    with patch.object(w.dialog_types['closing'],'exec',count), patch.object(w,'show_z_report') as z, patch.object(TouchMessage,'information'):
        w.close_current_shift()
    z.assert_called_once_with(auto_print=True)
    assert w.locked and w.cashier is None
    assert db.execute('SELECT status FROM shifts WHERE id=?',(shift,)).fetchone()[0] == 'closed'


def test_close_draft_confirmation_cancel_and_restart(scene, tmp_path):
    db, service, w = scene
    w.order.add_product(OrderLine(1,'สินค้า','ชิ้น',D(9),D(10)))
    w.order.bill_discount=D(3)
    # Do not refresh: close must flush even the latest edit.
    def press_exit(dialog):
        next(b for b in dialog.findChildren(QPushButton) if b.text()=='ปิดโปรแกรม').click()
        return dialog.result()
    with patch.object(TouchDialog,'exec',press_exit), patch.object(TouchMessage,'question',return_value=TouchMessage.No) as ask:
        assert not w.close()
        ask.assert_called_once()
    with patch.object(TouchDialog,'exec',press_exit), patch.object(TouchMessage,'question',return_value=TouchMessage.Yes):
        assert w.close()
    restarted = run_ui(service, app=APP, data_dir=tmp_path)
    try:
        assert restarted.locked
        assert restarted.order.lines[0].qty==9 and restarted.order.bill_discount==3
        assert restarted.order.grand_total()==87
    finally:
        restarted._allow_close=True;restarted.close();restarted.deleteLater()


def test_close_queue_alone_requires_confirmation(scene):
    db, service, w = scene
    with patch.object(service,'pending_sync_count',return_value=2), patch.object(TouchMessage,'question',return_value=TouchMessage.No) as ask:
        dialog=w.build_close_dialog()
        next(b for b in dialog.findChildren(QPushButton) if b.text()=='ปิดโปรแกรม').click()
        assert dialog.result()!=QDialog.Accepted
        ask.assert_called_once()


def test_minimize_and_close_cancel_cannot_unlock(scene):
    db, service, w = scene
    enter(w,db)
    w.showMinimized();APP.processEvents();w.showNormal();APP.processEvents()
    assert w.locked and w.pages.currentWidget() is w.lock_page
    with patch.object(TouchDialog,'exec',return_value=QDialog.Rejected):
        event=QCloseEvent();w.closeEvent(event)
    assert not event.isAccepted() and w.locked
    dialog=w.build_close_dialog();dialog.show();APP.processEvents()
    QTest.keyClick(dialog,Qt.Key_Escape)
    assert dialog.result()==QDialog.Rejected


def test_wrong_cashier_cannot_take_over_open_shift(scene):
    db, service, w=scene
    enter(w,db);w.lock_session()
    db.execute("INSERT INTO local_cashiers(id,code,name,pin_hash,synced_at) VALUES(2,'OTHER','คนอื่น','',?)",(now(),));db.commit()
    with patch.object(w,'pin_login',return_value=db.execute('SELECT * FROM local_cashiers WHERE id=2').fetchone()), patch.object(TouchMessage,'warning'):
        w.start_work()
    assert w.locked and w.cashier is None
    assert db.execute("SELECT cashier_id FROM shifts WHERE status='open'").fetchone()[0]==1


def test_ui_has_no_native_message_or_input_dialogs():
    from pos_python import ui
    source=Path(ui.__file__).read_text()
    assert 'QMessageBox.' not in source and 'QInputDialog.' not in source
