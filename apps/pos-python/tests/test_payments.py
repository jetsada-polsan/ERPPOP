"""Independent payment boundary, persistence, retry, receipt and touch checks."""
from decimal import Decimal as D
from pathlib import Path
import pytest
from PySide6.QtWidgets import QApplication, QDialog, QPushButton
from pos_python.database import connect
from pos_python.services import PosService, CartLine, now
from pos_python.payments import validate_payment, payload_fields
from pos_python.mock_printer import receipt_copies
from pos_python.sync_service import SyncService
from pos_python.order import OrderLine
from pos_python.ui import run_ui

APP = QApplication.instance() or QApplication([])

@pytest.fixture
def scene(tmp_path):
    db = connect(tmp_path/'payments.db')
    db.execute("INSERT INTO local_cashiers(id,server_id,code,name,pin_hash,synced_at) VALUES(1,7,'TEST','ตัวอย่าง','',?)", (now(),))
    db.execute("INSERT INTO products(id,server_id,sku,name,unit_name,price,updated_at) VALUES(1,9,'P','สินค้า','ชิ้น','100.05',?)", (now(),))
    db.commit()
    pos = PosService(db)
    shift = pos.open_shift(1,'TEST',1,D('200'))
    pos.bind_server_shift(shift,91)
    yield db,pos,shift
    db.close()

@pytest.mark.parametrize('method', ['cash','transfer','mixed','credit_card','cheque'])
def test_methods_persist_retry_receipts_and_totals(scene, method):
    db,pos,shift=scene
    sale=pos.checkout(document_no='PAY',branch_id=1,terminal_id='TEST',shift_id=shift,cashier_id=1,
        lines=[CartLine(1,D(1),D('100.05'))], payment_method=method,paid_amount=D('120') if method=='cash' else D('100.05'),
        cash_amount=D('30.02'),payment_confirmed=True,transfer_account_last4='0042',payment_reference='TEST-REF',sale_uuid='payment-id')
    rows=db.execute('SELECT * FROM payments WHERE sale_id=? ORDER BY id',(sale,)).fetchall()
    fields=payload_fields(rows)
    assert fields['method']==method
    expected=dict(cash=D(0),transfer=D(0),credit_card=D(0),cheque=D(0))
    if method=='mixed': expected.update(cash=D('30.02'),transfer=D('70.03'))
    else: expected[method]=D('100.05')
    assert pos.shift_payment_totals(shift)==expected
    assert pos.shift_cash_summary(shift).expected_cash==D(200)+expected['cash']
    copies=receipt_copies(db,sale)
    assert len(copies)==(3 if method in ('transfer','mixed') else 2)
    assert 'TEST-REF' in copies[0]
    if method=='mixed':
        assert fields['cash_amount']=='30.02' and fields['transfer_amount']=='70.03'
        assert fields['change_amount']=='0.00'
        assert '30.02' in copies[0] and '70.03' in copies[0] and '0042' in copies[0]
    class Api:
        calls=[]
        def post(self,path,payload,**kw):
            self.calls.append((path,payload,kw))
            if len(self.calls)==1: raise TimeoutError('offline')
            return dict(success=True,receipt_no='ERP-TEST')
    api=Api(); sync=SyncService(db,api)
    with pytest.raises(RuntimeError, match='offline'): sync.sync_sale('payment-id')
    sync.sync_sale('payment-id')
    assert api.calls[0]==api.calls[1]
    assert api.calls[1][1]['method']==method
    assert api.calls[1][2]['idempotency_key']=='payment-id'
    assert db.execute('SELECT count(*) FROM sales').fetchone()[0]==1
    assert dict(pos.daily_sales_summary().payments)=={k:v for k,v in expected.items() if v}
    pos.void_sale(sale,cashier_id=1,reason='test')
    assert all(v==0 for v in pos.shift_payment_totals(shift).values())

@pytest.mark.parametrize('cash', ['0','100.05','100.06','-1','NaN','Infinity'])
def test_invalid_mixed_cash(cash):
    with pytest.raises(ValueError): validate_payment('mixed','100.05','100.05',cash,'0042',True,'REF')

@pytest.mark.parametrize('last4', [None,'','123','12345','12a4','１２３４'])
@pytest.mark.parametrize('method', ['transfer','mixed'])
def test_last4_required(method,last4):
    with pytest.raises(ValueError): validate_payment(method,100,100,30,last4,True,'REF')

@pytest.mark.parametrize('method', ['mixed','credit_card','cheque'])
def test_reference_required(method):
    with pytest.raises(ValueError): validate_payment(method,100,100,30,'0042',True,' ')

def test_decimal_rounding_and_unknown_method():
    assert validate_payment('mixed','100.05','100.05','30.025','0042',True,'REF')[1][1]==D('70.02')
    with pytest.raises(ValueError): validate_payment('cash',100,99)
    with pytest.raises(ValueError): validate_payment('unknown',100,100)
    assert validate_payment('transfer',100,100,0,'0042',False,None)[0][0] == 'transfer'

@pytest.mark.parametrize('method', ['cash','transfer'])
def test_touch_payment_page(scene,method):
    db,pos,shift=scene
    w=run_ui(pos,app=APP); w.showNormal();w.resize(1024,768)
    w.order.add_product(OrderLine(1,'สินค้า','ชิ้น',D(1),D('100.05')))
    dialog=w.dialog_types['payment'](w,w.order)
    dialog.set_payment_method(method); dialog.show(); APP.processEvents()
    assert dialog.width()<=1024 and dialog.height()<=768
    assert set(dialog.method_buttons) == {'cash', 'transfer'}
    for button in dialog.method_buttons.values():
        assert button.isVisible() and button.height()>=50
        assert dialog.rect().contains(button.mapTo(dialog,button.rect().bottomRight()))
    buttons=list(dialog.method_buttons.values())
    for left,right in zip(buttons,buttons[1:]):
        assert not left.geometry().intersects(right.geometry())
    for field in (dialog.payment_reference,dialog.transfer_account_last4):
        if field.isVisible():
            assert dialog.rect().contains(field.mapTo(dialog,field.rect().bottomRight()))
    dialog.transfer_account_last4.setText('0042')
    dialog.confirm(); assert dialog.result()==QDialog.DialogCode.Accepted
    w._allow_close=True;w.close();w.deleteLater();APP.processEvents()

def test_offline_restart_preserves_mixed_payload(scene):
    db,pos,shift=scene
    sale=pos.checkout(document_no='RESTART',branch_id=1,terminal_id='TEST',shift_id=shift,cashier_id=1,
        lines=[CartLine(1,D(1),D('100.05'))],payment_method='mixed',paid_amount=D('100.05'),cash_amount=D('30.02'),
        transfer_account_last4='0042',payment_reference='REF',payment_confirmed=True,sale_uuid='restart-id')
    before=payload_fields(db.execute('SELECT * FROM payments WHERE sale_id=? ORDER BY id',(sale,)).fetchall())
    other=connect(Path(db.execute('PRAGMA database_list').fetchone()['file']))
    try:
        after=payload_fields(other.execute('SELECT * FROM payments WHERE sale_id=? ORDER BY id',(sale,)).fetchall())
        assert after==before
        assert other.execute("SELECT count(*) FROM sync_outbox WHERE aggregate_uuid='restart-id'").fetchone()[0]==1
    finally: other.close()


def test_transfer_qr_page_fits(scene):
    db,pos,_=scene
    w=run_ui(pos,app=APP);w.showNormal();w.resize(1024,768)
    w.order.add_product(OrderLine(1,'สินค้า','ชิ้น',D(1),D('100.05')))
    dialog=w.dialog_types['payment'](w,w.order)
    # Public test-only merchant identifier; no authentication credential.
    dialog.qr_config={'merchant_ref':'0812345678'}
    dialog.set_payment_method('transfer');dialog.show();APP.processEvents()
    assert dialog.qr_payload and dialog.qr_image.isVisible()
    assert dialog.width()<=1024 and dialog.height()<=768
    dialog.reject();w._allow_close=True;w.close();w.deleteLater();APP.processEvents()

@pytest.mark.parametrize('cash', ['0','100.05'])
def test_invalid_mixed_creates_no_financial_records(scene,cash):
    db,pos,shift=scene
    with pytest.raises(ValueError):
        pos.checkout(document_no='INVALID',branch_id=1,terminal_id='TEST',shift_id=shift,cashier_id=1,
            lines=[CartLine(1,D(1),D('100.05'))],payment_method='mixed',paid_amount=D('100.05'),
            cash_amount=D(cash),payment_confirmed=True,transfer_account_last4='0042',payment_reference='REF')
    for table in ('sales','payments','sale_items','print_jobs','sync_outbox'):
        assert db.execute(f'SELECT count(*) FROM {table}').fetchone()[0]==0
