"""0.8.9 cashier regressions; no network or usable credentials."""
from decimal import Decimal as D
from types import SimpleNamespace
from unittest.mock import Mock, patch
import pytest
from PySide6.QtWidgets import QApplication, QDialog, QPushButton
from pos_python.database import connect
from pos_python.services import PosService, now
from pos_python.order import Order, OrderLine
from pos_python.promotions import QtyPromotion, promotion_discounts
from pos_python.ui import run_ui, cashier_error, receipt_action_problem, sale_status_label


def line(qty, price='50'):
    return OrderLine(1, 'สินค้า', 'ชิ้น', D(qty), D(price))


@pytest.mark.parametrize('kind,extra,discount', [
    ('bundle_price', {'bundle_price': D(100)}, D(50)),
    ('discount', {'discount_type': 'percent', 'discount_value': D(10)}, D(15)),
    ('discount', {'discount_type': 'baht', 'discount_value': D(12)}, D(12)),
])
def test_aggregate_2_plus_2_uses_catalog_price(kind, extra, discount):
    lines = [line('2'), line('2')]
    lines[0].unit_price = D(40)
    promo = QtyPromotion(1, kind, D(3), **extra)
    assert sum(promotion_discounts(lines, [promo]).values()) == discount
    order = Order(lines=lines, promotions=[promo])
    assert order.promotion_total() == discount
    assert sum(x.promo_discount for x in order.to_cart_lines()) == discount


def test_aggregate_gifts_and_fractional_quantity():
    promo = QtyPromotion(1, 'free_item', D(3), free_product_id=2, free_qty=D('1.00000000'), free_price=D(10))
    order = Order(lines=[line('2'), line('2')], promotions=[promo])
    assert order.free_items()[0].qty == 1
    assert Order(lines=[line('1.4'), line('1.5')], promotions=[promo]).free_items() == []
    order.lines = [line('3.5'), line('3.5')]
    assert order.free_items()[0].qty == 2


@pytest.fixture
def till(tmp_path):
    app = QApplication.instance() or QApplication([])
    db = connect(tmp_path/'089.db')
    db.execute("INSERT INTO products(id,server_id,sku,name,unit_name,price,updated_at) VALUES(1,101,'104011','BB-B ก้าวหน้า (ถุง10kg.)','กิโลกรัม','50',?)", (now(),))
    db.execute("INSERT INTO products(id,server_id,sku,name,unit_name,price,updated_at) VALUES(2,102,'GIFT','ของแถม','ชิ้น','10',?)", (now(),))
    # Empty hash cannot authenticate; this fixture never provisions a PIN.
    db.execute("INSERT INTO local_cashiers(id,server_id,code,name,pin_hash,role,synced_at) VALUES(1,901,'M','หัวหน้าทดสอบ','','manager',?)", (now(),))
    db.commit()
    service = PosService(db)
    shift = service.open_shift(1,'TEST',1,D(0))
    service.bind_server_shift(shift,201)
    api = Mock()
    api.post.return_value = {'success': True}
    provisioning = SimpleNamespace(api=api, verify_approver=Mock())
    online = SimpleNamespace(online=True,branch_id=1,terminal_id='TEST',profile={},worker=SimpleNamespace(online=True,pending=0,stop=Mock()),error='',provisioning=provisioning)
    window = run_ui(service,online=online,app=app,data_dir=tmp_path)
    window.showNormal(); window.resize(1024,768)
    window.shift_id = shift
    window.cashier = db.execute("SELECT * FROM local_cashiers WHERE id=1").fetchone()
    window.locked = False
    window.pages.setCurrentIndex(0)
    app.processEvents()
    yield window, service, online, app
    window._allow_close=True; window.close(); db.close()


def test_search_clear_and_select_and_scale_rule(till):
    window, service, _, app = till
    window.scan.setText('ก้าวหน้า'); app.processEvents()
    window.clear_search.click(); app.processEvents()
    assert window.scan.text() == ''
    assert window.scan.hasFocus()
    assert window.clear_search.width() >= 56 and window.clear_search.height() >= 56
    window.scan.setText('ก้าวหน้า')
    product = service.db.execute('SELECT * FROM products WHERE id=1').fetchone()
    assert not window.is_scale_product(product)  # Owner must decide kg-only policy.
    window.add_product_row(product); app.processEvents()
    assert window.scan.text() == '' and window.scan.hasFocus()
    assert window.order.lines[0].qty == 1
    assert window.grid.count() == 2
    assert sum(b.isVisible() for b in window.grid_host.findChildren(QPushButton, 'productTile')) == 2


@pytest.mark.parametrize('qty,text', [('1.00000000','1'),('1.50000000','1.5'),('1.25000000','1.25')])
def test_gift_quantity_display(till,qty,text):
    window, _, _, _ = till
    window.order.lines=[line('3')]
    window.order.promotions=[QtyPromotion(1,'free_item',D(3),free_product_id=2,free_qty=D(qty),free_price=D(10))]
    with patch("pos_python.ui.active_promotions", return_value=window.order.promotions):
        window.refresh_order()
    assert window.table.item(1,2).text() == text


def sale_fixture(service):
    sale_id=service.checkout(document_no='TEST-089',branch_id=1,terminal_id='TEST',shift_id=1,cashier_id=1,
        lines=Order(lines=[line('1')]).to_cart_lines(),payment_method='cash',paid_amount=D(50),sale_uuid='089-sale')
    service.db.execute("UPDATE sales SET sync_status='synced',server_receipt_no='ERP-089' WHERE id=?", (sale_id,))
    service.db.commit()
    return sale_id


@pytest.mark.parametrize('is_return', [False,True])
def test_receipt_action_uses_reason_pad_verify_then_erp(till,is_return):
    window, service, online, app = till
    sale_id=sale_fixture(service)
    def reason(dialog):
        buttons=[b for b in dialog.findChildren(QPushButton) if b.text()=='คิดสินค้าผิด']
        assert buttons and buttons[0].minimumHeight() >= 56
        buttons[0].click()
        assert dialog.entry.text()=='คิดสินค้าผิด'
        return QDialog.Accepted
    def manager(dialog):
        dialog.choice=901
        return QDialog.Accepted
    # A non-credential sentinel crosses only mocks; never enters storage/network.
    pin=object()
    values=[('0.5',True),(pin,True)] if is_return else [(pin,True)]
    calls=[]
    online.provisioning.verify_approver.side_effect=lambda *a: calls.append('verify')
    online.provisioning.api.post.side_effect=lambda *a: calls.append(a[0]) or {'success':True}
    with patch('pos_python.touch_dialogs.TextDialog.exec',reason), patch('pos_python.touch_dialogs.ChoiceDialog.exec',manager), patch('pos_python.touch_dialogs.ValueDialog.get_value',side_effect=values), patch('pos_python.touch_dialogs.TouchMessage.information') as info:
        window.receipt_action(sale_id,is_return)
    endpoint='/api/pos/receipt/'+('return' if is_return else 'void')
    assert calls==['verify',endpoint]
    payload=online.provisioning.api.post.call_args.args[1]
    assert payload['reason']=='คิดสินค้าผิด' and 'approver_pin' not in payload
    assert service.db.execute('SELECT is_void FROM sales WHERE id=?',(sale_id,)).fetchone()[0] == (not is_return)
    if is_return:
        assert payload['items']==[{'product_id':101,'qty':'0.5'}]
        assert 'ก่อนคืนเงิน' in info.call_args.args[2]


@pytest.mark.parametrize('change,online,shift,word', [
    ({},True,None,'เปิดกะ'), ({},False,{'server_id':1},'อินเทอร์เน็ต'),
    ({},True,{'server_id':None},'กะนี้'),
    ({'server_receipt_no':None},True,{'server_id':1},'บิลนี้'),
    ({'sync_status':'failed'},True,{'server_id':1},'บิลนี้'),
    ({'is_void':1},True,{'server_id':1},'ยกเลิกแล้ว'),
])
def test_receipt_blockers_explain_next_step(change,online,shift,word):
    sale=dict(is_void=0,sync_status='synced',server_receipt_no='R');sale.update(change)
    assert word in receipt_action_problem(sale,shift,online)


def test_failed_approval_never_voids(till):
    window,service,online,_=till
    sale_id=sale_fixture(service)
    def reason(d): d.entry.setText('คิดสินค้าผิด'); return QDialog.Accepted
    def manager(d): d.choice=901; return QDialog.Accepted
    online.provisioning.verify_approver.side_effect=RuntimeError('payload token server_id')
    with patch('pos_python.touch_dialogs.TextDialog.exec',reason),patch('pos_python.touch_dialogs.ChoiceDialog.exec',manager),patch('pos_python.touch_dialogs.ValueDialog.get_value',return_value=(object(),True)),patch('pos_python.touch_dialogs.TouchMessage.warning') as warning:
        window.receipt_action(sale_id,False)
    online.provisioning.api.post.assert_not_called()
    assert service.db.execute('SELECT is_void FROM sales WHERE id=?',(sale_id,)).fetchone()[0]==0
    assert 'payload' not in warning.call_args.args[2]


def test_plain_status_and_errors():
    for word in ['token','sync','preview','payload','outbox','server_id']:
        assert word not in cashier_error(RuntimeError('ผิดพลาด '+word))
    assert sale_status_label('failed')=='ส่งไม่สำเร็จ'


@pytest.mark.parametrize('change,word', [
    ("sale_datetime='2000-01-01T00:00:00+00:00'",'บิลวันนี้'),
    ("sync_status='pending',server_receipt_no=NULL",'ยังส่ง ERP'),
])
def test_receipt_action_blocks_before_reason_or_pin(till,change,word):
    window,service,online,_=till
    sale_id=sale_fixture(service)
    service.db.execute('UPDATE sales SET '+change+' WHERE id=?',(sale_id,));service.db.commit()
    with patch('pos_python.touch_dialogs.TouchMessage.warning') as warning,patch('pos_python.touch_dialogs.TextDialog.exec') as reason:
        window.receipt_action(sale_id,False)
    assert word in warning.call_args.args[2]
    reason.assert_not_called()
    online.provisioning.verify_approver.assert_not_called()
    online.provisioning.api.post.assert_not_called()


def test_api_timeout_does_not_mark_void_or_offer_blind_retry(till):
    window,service,online,_=till
    sale_id=sale_fixture(service)
    def reason(d):
        d.entry.setText('เหตุผลที่พิมพ์เอง');return QDialog.Accepted
    def manager(d): d.choice=901;return QDialog.Accepted
    online.provisioning.api.post.side_effect=TimeoutError('payload')
    with patch('pos_python.touch_dialogs.TextDialog.exec',reason),patch('pos_python.touch_dialogs.ChoiceDialog.exec',manager),patch('pos_python.touch_dialogs.ValueDialog.get_value',return_value=(object(),True)),patch('pos_python.touch_dialogs.TouchMessage.warning') as warning:
        window.receipt_action(sale_id,False)
    assert 'ตรวจบิลใน ERP ก่อนทำซ้ำ' in warning.call_args.args[2]
    assert service.db.execute('SELECT is_void FROM sales WHERE id=?',(sale_id,)).fetchone()[0]==0
    assert online.provisioning.api.post.call_args.args[1]['reason']=='เหตุผลที่พิมพ์เอง'
    assert 'approver_pin' not in online.provisioning.api.post.call_args.args[1]


def test_pin_dialog_masks_and_clears_after_accept_or_cancel(till, caplog):
    from pos_python.touch_dialogs import ValueDialog
    from PySide6.QtWidgets import QLineEdit, QLabel
    window, _, _, _ = till
    for accepted in (True, False):
        seen = []
        def enter(dialog):
            # UI-only sentinel, never authenticated or stored.
            dialog.pad.press('1')
            assert dialog.entry.echoMode() == QLineEdit.Password
            assert dialog.entry.displayText() != dialog.entry.text()
            assert dialog.dots.text() == '● ○ ○ ○'
            assert all('1' not in label.text() for label in dialog.findChildren(QLabel))
            seen.append(dialog)
            return QDialog.Accepted if accepted else QDialog.Rejected
        with patch.object(ValueDialog, 'exec', enter):
            value, ok = ValueDialog.get_value(window, 'PIN', 'ใส่รหัส', pin=True)
        assert ok == accepted
        assert value == ('1' if accepted else '')
        assert seen[0].entry.text() == ''
        assert seen[0].dots.text() == '○ ○ ○ ○'
    assert caplog.text == ''


def test_history_searches_local_and_erp_numbers(till):
    window, service, _, app = till
    sale_id = sale_fixture(service)
    def select(dialog):
        assert dialog.search is not None
        dialog.show()
        dialog.search.setText('ERP-089')
        app.processEvents()
        assert len([b for b in dialog.choice_buttons if not b.isHidden()]) == 1
        dialog.search.setText('missing-bill')
        assert all(b.isHidden() for b in dialog.choice_buttons)
        dialog.search.clear()
        assert all(not b.isHidden() for b in dialog.choice_buttons)
        dialog.reject()
        return QDialog.Rejected
    with patch('pos_python.touch_dialogs.ChoiceDialog.exec', select):
        window.show_history()


def test_approver_list_excludes_cashiers_inactive_and_revoked(till):
    window, service, _, _ = till
    sale_id = sale_fixture(service)
    for ident, role, active, revoked in [(2,'cashier',1,None),(3,'manager',0,None),(4,'manager',1,'revoked')]:
        service.db.execute('INSERT INTO local_cashiers(id,server_id,code,name,pin_hash,role,active,revoked_at,synced_at) VALUES(?,?,?,?,?,?,?,?,?)',
            (ident,900+ident,str(ident),'excluded','','%s' % role,active,revoked,now()))
    service.db.commit()
    def reason(dialog):
        dialog.entry.setText('คิดสินค้าผิด')
        return QDialog.Accepted
    def manager(dialog):
        assert [b.text() for b in dialog.choice_buttons] == ['หัวหน้าทดสอบ']
        return QDialog.Rejected
    with patch('pos_python.touch_dialogs.TextDialog.exec', reason), patch('pos_python.touch_dialogs.ChoiceDialog.exec', manager):
        window.receipt_action(sale_id, False)
