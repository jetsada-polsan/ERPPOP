"""Touch interaction and shift safeguards, using a temporary offline database."""
import os
os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')
from decimal import Decimal as D
from types import SimpleNamespace
from unittest.mock import Mock, patch
import pytest
from PySide6.QtCore import QEvent
from PySide6.QtWidgets import QApplication, QDialog, QPushButton
from pos_python.database import connect
from pos_python.services import PosService, now, CartLine
from pos_python.provisioning import ProvisioningService
from pos_python.touch_dialogs import ValueDialog, TextDialog


def test_cashier_pin_auto_submits_only_after_four_digits():
    dialog = ValueDialog(title='PIN', pin=True, auto_pin=True)
    for digit in '123':
        dialog.pad.press(digit)
    assert dialog.result() != QDialog.DialogCode.Accepted
    dialog.pad.press('4')
    assert dialog.result() == QDialog.DialogCode.Accepted
    assert dialog.entry.text() == '1234'
    dialog.pad.press('5')
    assert dialog.entry.text() == '1234'
from pos_python.ui import run_ui

_APP = QApplication.instance() or QApplication([])

@pytest.fixture
def scene(tmp_path):
    app = QApplication.instance() or QApplication([])
    db = connect(tmp_path / 'test.db')
    # No usable credentials in this UI fixture.
    db.execute("INSERT INTO local_cashiers(id,code,name,pin_hash,synced_at,server_id) VALUES(1,'UI','คนขาย','',?,7)", (now(),))
    db.execute("INSERT INTO products(id,sku,name,unit_name,price,updated_at) VALUES(1,'P','สินค้า','ชิ้น','10',?)", (now(),))
    db.commit()
    service = PosService(db)
    shift = service.open_shift(1, 'TEST', 1, D(100))
    db.execute('UPDATE shifts SET server_id=91 WHERE id=?', (shift,));db.commit()
    api = Mock()
    api.get.return_value = {'shift': {'id':91, 'expected_cash':'125.50'}}
    api.post.return_value = {'success':True}
    online = SimpleNamespace(branch_id=1, terminal_id='TEST', profile={}, online=True,
        provisioning=ProvisioningService(db, api), worker=SimpleNamespace(online=True, pending=0, stop=lambda:None))
    window = run_ui(service, online=online, app=app)
    window.showNormal();window.resize(1024,768)
    window.shift_id = shift
    window.cashier = db.execute('SELECT * FROM local_cashiers WHERE id=1').fetchone()
    yield app, db, service, window, api
    window._allow_close = True; window.close();window.deleteLater()
    app.sendPostedEvents(None, QEvent.DeferredDelete)
    db.close()


def test_pin_pad_buttons_and_keyboard_validation():
    app = QApplication.instance() or QApplication([])
    dialog = ValueDialog(title='PIN', pin=True)
    assert not dialog.submit.isEnabled()
    # Only test transient UI input; it is never stored or sent.
    for key in ('9','8','7','6'):
        next(b for b in dialog.pad.findChildren(QPushButton) if b.text()==key).click()
    assert dialog.submit.isEnabled()
    assert dialog.dots.text().count('●') == 4
    dialog.pad.press('⌫');assert not dialog.submit.isEnabled()
    dialog.pad.press('ล้าง');assert dialog.entry.text()==''
    dialog.entry.insert('x');assert dialog.entry.text()==''
    dialog.close()


def test_numpad_decimal_and_selection():
    app = QApplication.instance() or QApplication([])
    dialog = ValueDialog()
    for key in ('1','2','.','5','.'):
        dialog.pad.press(key)
    assert dialog.entry.text() == '12.5'
    dialog.entry.selectAll();dialog.pad.press('3')
    assert dialog.entry.text() == '3'
    dialog.pad.press('⌫');dialog.pad.press('.')
    assert dialog.entry.text() == '0.'
    dialog.close()


def test_closing_difference_blocks_pending_sale_and_uses_erp(scene):
    app, db, service, window, api = scene
    assert window.expected_shift_cash() == D('125.50')
    api.get.assert_called_with('/api/pos/shift?branch_id=1&cashier_id=7')
    dialog = window.dialog_types['closing'](window, D('125.50'))
    dialog.amount.setText('120');assert 'ขาด 5.50' in dialog.difference.text()
    dialog.amount.setText('130');assert 'เกิน 4.50' in dialog.difference.text()
    service.checkout(document_no='UI-1', branch_id=1, terminal_id='TEST', shift_id=window.shift_id,
        cashier_id=1, lines=[CartLine(1,D(1),D(10))],payment_method='cash',paid_amount=D(10))
    dialog.refresh();assert not dialog.submit.isEnabled()
    dialog.accept();assert dialog.result() != QDialog.Accepted
    db.execute("UPDATE sales SET sync_status='failed'");db.commit()
    dialog.refresh();assert not dialog.submit.isEnabled()
    db.execute("UPDATE sales SET sync_status='synced'");db.commit()
    dialog.refresh();assert dialog.submit.isEnabled()
    dialog.show();app.processEvents()
    assert dialog.width() <= 1024 and dialog.height() <= 768
    dialog.close()


def test_cash_drop_keeps_reference_and_api_uuid(scene):
    app, db, service, window, api = scene
    def fill_details(dialog):
        from PySide6.QtWidgets import QLineEdit
        fields = dialog.findChildren(QLineEdit)
        fields[0].setText('25')
        fields[1].setText('BAG-01')
        fields[2].setText('นำส่งเงินระหว่างกะ')
        return QDialog.Accepted
    from pos_python.touch_dialogs import TouchDialog
    with patch.object(TouchDialog, 'exec', fill_details):
        window.record_cash_movement(drop_only=True)
    row = db.execute('SELECT * FROM cash_movements').fetchone()
    assert row['reference_no'] == 'BAG-01'
    args, kwargs = api.post.call_args
    assert args[0] == '/api/pos/shift/cash-movement'
    assert args[1]['shift_id'] == 91 and args[1]['movement_type'] == 'drop'
    assert args[1]['amount'] == '25.00' and args[1]['reference_no'] == 'BAG-01'
    assert kwargs['idempotency_key'] == row['movement_uuid']
    assert row['sync_status'] == 'synced'


def test_compact_popups_fit_and_have_large_keys(scene):
    app, db, service, window, api = scene
    for name in ('seller','opening','closing'):
        cls=window.dialog_types[name]
        dialog=cls() if name=='seller' else cls(window,D(100)) if name=='closing' else cls(window)
        dialog.show();app.processEvents()
        assert dialog.width() <= 1024 and dialog.height() <= 768, (name,dialog.size())
        if hasattr(dialog,'pad'):
            for key in dialog.pad.findChildren(QPushButton):
                assert key.width()>=64 and key.height()>=64
        dialog.close()


def test_return_sends_server_ids_and_approval_without_storing_pin(scene):
    from pos_python.touch_dialogs import ChoiceDialog
    from pos_python.touch_dialogs import TouchMessage
    app, db, service, window, api = scene
    db.execute('UPDATE products SET server_id=42 WHERE id=1')
    db.execute("INSERT INTO local_cashiers(id,server_id,code,name,pin_hash,synced_at,role) VALUES(2,77,'MANAGER','หัวหน้า','',?,'manager')", (now(),))
    sale_id = service.checkout(document_no='UI-RETURN',branch_id=1,terminal_id='TEST',shift_id=window.shift_id,
        cashier_id=1,lines=[CartLine(1,D(1),D(10))],payment_method='cash',paid_amount=D(10))
    db.execute("UPDATE sales SET server_receipt_no='ERP-1', sync_status='synced' WHERE id=?", (sale_id,));db.commit()
    def fill_reason(dialog):
        dialog.entry.setText('สินค้าเสียหาย')
        return QDialog.Accepted
    def choose(dialog):
        dialog.choice=77
        return QDialog.Accepted
    # Transient simulated input only, never a provisioned credential.
    with patch.object(TextDialog,'exec',fill_reason), patch.object(ChoiceDialog,'exec',choose), patch.object(ValueDialog,'get_value',side_effect=[('0.5',True),('9876',True)]), patch.object(TouchMessage,'information'):
        window.receipt_action(sale_id,True)
    path, payload=api.post.call_args.args
    assert path=='/api/pos/receipt/return'
    assert payload['shift_id']==91 and payload['approver_id']==77
    assert payload['receipt_no']=='ERP-1' and payload['items']==[{'product_id':42,'qty':'0.5'}]
    assert 'approver_pin' not in payload  # removed immediately after the request
    assert db.execute('SELECT is_void FROM sales WHERE id=?',(sale_id,)).fetchone()[0]==0
    assert not db.execute("SELECT 1 FROM sync_outbox WHERE payload LIKE '%9876%'").fetchone()


def test_close_service_rechecks_pending_after_dialog(scene):
    app, db, service, window, api = scene
    service.checkout(document_no='UI-RACE',branch_id=1,terminal_id='TEST',shift_id=window.shift_id,
        cashier_id=1,lines=[CartLine(1,D(1),D(10))],payment_method='cash',paid_amount=D(10))
    with pytest.raises(ValueError,match='ต้องส่งบิลค้าง'):
        service.close_shift(shift_id=window.shift_id,counted_cash=D(110))
    assert db.execute('SELECT status FROM shifts WHERE id=?',(window.shift_id,)).fetchone()[0]=='open'


def test_central_hold_uses_server_ids_and_restores_discount(scene):
    from pos_python.order import OrderLine
    from pos_python.touch_dialogs import ChoiceDialog
    app, db, service, window, api = scene
    db.execute('UPDATE products SET server_id=42 WHERE id=1');db.commit()
    window.order.add_product(OrderLine(1,'สินค้า','ชิ้น',D(2),D(10)))
    window.order.bill_discount=D(3)
    window.order.adjustment_approved_by='คนขาย'
    with patch.object(TextDialog,'exec',return_value=QDialog.Accepted):
        window.hold_order()
    path, payload=api.post.call_args.args
    assert path=='/api/pos/held-bills' and payload['shift_id']==91 and payload['cashier_id']==7
    assert payload['payload']['cart'][0]['id']==42
    assert payload['payload']['python_snapshot']['lines'][0]['product_id']==42
    assert not window.order.lines
    api.get.return_value={'held_bills':[dict(id=9,label='ลูกค้า',total_amount=17,**payload['payload'])]}
    def choose(dialog):
        dialog.choice=('remote',0)
        return QDialog.Accepted
    with patch.object(ChoiceDialog,'exec',choose):
        window.recall_order()
    assert api.post.call_args.args[0]=='/api/pos/held-bills/9/resume'
    assert window.order.lines[0].product_id==1 and window.order.bill_discount==3
    assert window.order.grand_total()==17


def test_hold_falls_back_to_this_device_when_erp_refuses(scene):
    """เจอที่ร้าน 10 พ.ย.: กะยังไม่ขึ้น ERP → พักบิลไม่ได้เลย ต้องพักไว้ในเครื่องแทน"""
    from pos_python.order import OrderLine
    from pos_python.settings_service import SettingsService
    from pos_python.touch_dialogs import TouchMessage
    app, db, service, window, api = scene
    db.execute('UPDATE products SET server_id=42 WHERE id=1');db.commit()
    api.post.side_effect = RuntimeError('พักบิลได้เฉพาะกะที่กำลังเปิดของตนเอง')
    window.order.add_product(OrderLine(1,'สินค้า','ชิ้น',D(2),D(10)))
    with patch.object(TextDialog,'exec',return_value=QDialog.Accepted), patch.object(TouchMessage,'information') as told:
        window.hold_order()
    assert not window.order.lines
    held = SettingsService(db).get_device_setting('held_orders', [])
    assert len(held) == 1 and 'snapshot' in held[0]
    assert told.called
