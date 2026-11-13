"""Money and real touch protocol regressions, without credentials or external requests."""
from decimal import Decimal as D
from unittest.mock import Mock, patch
import pytest
from PySide6.QtWidgets import QLabel, QDialog
from pos_python.shift_float import cash, FloatClose, supports_float, z_float_text
from pos_python.touch_dialogs import TouchMessage, TouchDialog, ValueDialog
from pos_python.settings_service import SettingsService
from test_touch_popups import scene

POLICY = dict(default_float='2000', carried_float='2000', blind_count=True,
              cash_warning_threshold='10000', shift=dict(id=91, cash_warning=True))


def report(**extra):
    return dict(id=91, opening_cash='2000', carried_float='2000', opening_variance='0',
        opening_note='', cash_sales='13432.75', transfer_sales='0', card_sales='0', cheque_sales='0',
        cash_in='0', cash_drops='0', cash_payouts='0', expected_cash='15432.75', counted_cash='15432.75',
        cash_difference='0', float_kept='2000', deposit_amount='13432.75', deposit_reference='BAG-001',
        void_count=0, return_count=0, discount_total='0', **extra)


@pytest.mark.parametrize('value', ['-0.01','NaN','Infinity','0.001','1.999','junk'])
def test_reject_invalid_cash_without_rounding(value):
    with pytest.raises(ValueError): cash(value)


def test_protocol_exact_cents_and_final_payload():
    api = Mock()
    api.post.side_effect = [dict(preview=True,shift=report()), dict(success=True,shift=report(status='closed'))]
    p = FloatClose(api, POLICY, 91)
    r = p.inspect('15432.75','2000','BAG-001')
    assert r['deposit_amount'] == '13432.75'
    p.close()
    preview, final = api.post.call_args_list
    assert preview.args[1]['preview'] is True
    assert 'preview' not in final.args[1] and 'deposit_amount' not in final.args[1]
    assert final.args[1]['counted_cash'] == '15432.75'
    assert not p.preview


@pytest.mark.parametrize('policy', [{}, {'shift':None}, {'default_float':'2000'}])
def test_old_server_never_receives_preview(policy):
    api=Mock()
    assert not supports_float(policy)
    with pytest.raises(ValueError): FloatClose(api,policy,91)
    api.post.assert_not_called()


def test_bad_preview_and_oversized_float_are_blocked():
    api=Mock();api.post.return_value=dict(preview=True,shift=dict(report(),deposit_amount='13432.74'))
    p=FloatClose(api,POLICY,91)
    with pytest.raises(ValueError): p.inspect('15432.75','2000','BAG-001')
    assert p.preview is None
    with pytest.raises(ValueError): p.inspect('100','100.01')
    with pytest.raises(ValueError): p.close()
    assert api.post.call_count == 1


@pytest.mark.parametrize('carried, initial', [('2000','2000.00'), ('0','0.00'), (None,'2000.00')])
def test_opening_prefill_and_required_reason(scene,carried,initial):
    app,db,service,w,api=scene
    w.shift_policy=dict(POLICY,carried_float=carried)
    d=w.dialog_types['opening'](w)
    assert d.amount.text() == initial
    d.amount.setText('1950.01')
    with patch.object(TouchMessage,'warning') as warning:
        d.accept_value()
        if carried is not None:
            warning.assert_called_once();assert d.result() != QDialog.Accepted
            d.note.setText('รับเงินจริงต่างจากยอดยกมา');d.accept_value()
        else:
            warning.assert_not_called()
    assert d.result() == QDialog.Accepted
    assert d.opening_cash == D('1950.01')
    d.reject()


@pytest.mark.parametrize('field', ['amount','kept','reference','note'])
def test_blind_preview_invalidated_on_every_edit(scene,field):
    app,db,service,w,api=scene
    api.post.return_value=dict(preview=True,shift=report())
    p=FloatClose(api,POLICY,91);d=w.dialog_types['float_closing'](w,p,POLICY)
    labels=' '.join(x.text() for x in d.findChildren(QLabel))
    assert '15,432.75' not in labels and not d.amount.text() and not d.submit.isEnabled()
    d.amount.setText('15432.75');d.reference.setText('BAG-001');d.inspect()
    assert d.submit.isEnabled() and '13,432.75' in d.result_label.text()
    edits=dict(amount='15432.74',kept='1999.99',reference='BAG-002',note='ตรวจใหม่')
    getattr(d,field).setText(edits[field])
    assert not d.submit.isEnabled() and p.preview is None
    assert '13,432.75' not in d.result_label.text()
    d.accept();assert d.result()!=QDialog.Accepted
    with pytest.raises(ValueError):p.close()
    assert api.post.call_count==1
    d.reject()


def test_manager_can_see_server_expected_and_denominations(scene):
    app,db,service,w,api=scene
    policy=dict(POLICY,shift=dict(id=91,expected_cash='15432.75'))
    d=w.dialog_types['float_closing'](w,FloatClose(api,policy,91),policy)
    assert '15,432.75' not in ' '.join(x.text() for x in d.findChildren(QLabel))
    # 15 x 1000 + 4 x 100 + 1 x 20 + 1 x 10 + 1 x 2 + 1 x .50 + 1 x .25
    counts=['15','0','4','0','1','1','0','1','0','1','1']
    for entry, value in zip(d.coin_fields, counts): entry.setText(value)
    assert cash(d.amount.text())==D('15432.75')
    assert cash(d.kept.text())==D('2000')
    d.reject()


def test_warning_uses_server_flag_without_disclosing_balance(scene):
    app,db,service,w,api=scene
    w.locked=False;api.get.return_value=POLICY
    w.refresh_cash_warning()
    assert 'ถึงเกณฑ์' in w.cash_warning_label.text()
    assert '15,432' not in w.cash_warning_label.text()


def test_new_close_is_server_first_no_local_queue_and_z_twice(scene):
    app,db,service,w,api=scene
    api.get.return_value=POLICY
    api.post.side_effect=[dict(preview=True,shift=report()),dict(success=True,shift=report(status='closed'))]
    def count(d):
        d.amount.setText('15432.75');d.reference.setText('BAG-001');d.inspect();d.navigate(3);d.accept()
        return d.result()
    SettingsService(db).set_device_setting('windows_printer_queue','FAKE')
    with patch.object(w.dialog_types['float_closing'],'exec',count), patch.object(TouchDialog,'exec',return_value=QDialog.Rejected), patch('pos_python.printers.print_text_to_windows_queue') as printer:
        w.close_current_shift()
    assert db.execute('SELECT status FROM shifts').fetchone()[0]=='closed'
    assert db.execute("SELECT count(*) FROM sync_outbox WHERE aggregate_type='shift_close'").fetchone()[0]==0
    assert w.locked and printer.call_count==2
    for i,call in enumerate(printer.call_args_list,1):
        text=call.args[0]
        assert f'ใบที่ {i}/2' in text
        for word in ('ยกมา','ส่วนต่างรับกะ','นำส่งระหว่างกะ','ควรมี','นับได้','ขาด/เกิน','13,432.75','BAG-001','เงินทอนเก็บ','ผู้ส่ง','ผู้รับ'): assert word in text


def test_timeout_does_not_close_local_or_retry(scene):
    app,db,service,w,api=scene
    api.get.return_value=POLICY
    api.post.side_effect=[dict(preview=True,shift=report()),TimeoutError('uncertain')]
    def count(d):
        d.amount.setText('15432.75');d.reference.setText('BAG-001');d.inspect();d.navigate(3);d.accept();return d.result()
    with patch.object(w.dialog_types['float_closing'],'exec',count),patch.object(TouchMessage,'warning'):
        w.close_current_shift()
        assert not w.ensure_sale_session()
    assert db.execute('SELECT status FROM shifts').fetchone()[0]=='open'
    assert not db.execute("SELECT 1 FROM sync_outbox WHERE aggregate_type='shift_close'").fetchone()
    assert api.post.call_count==2 and w.locked


def test_z_old_missing_fields_are_not_invented():
    text=z_float_text({})
    assert 'เงินทอนเก็บ: ไม่มีข้อมูล' in text and 'เงินนำส่ง: ไม่มีข้อมูล' in text


def test_opening_reason_survives_offline_queue(scene):
    from pos_python.sync_service import SyncService
    app,db,service,w,api=scene
    db.execute('UPDATE shifts SET server_id=NULL');db.commit()
    service.queue_shift_open(w.shift_id,opening_note='รับกะขาด 0.01')
    aggregate=db.execute("SELECT aggregate_uuid FROM sync_outbox WHERE aggregate_type='shift_open'").fetchone()[0]
    api.post.return_value=dict(success=True,shift=dict(id=91))
    SyncService(db,api).sync_shift_open(aggregate)
    assert api.post.call_args.args[1]['opening_note']=='รับกะขาด 0.01'
    assert db.execute('SELECT server_id FROM shifts').fetchone()[0]==91


def test_legacy_ui_close_never_sends_preview(scene):
    app,db,service,w,api=scene
    def count(d):
        d.amount.setText('100');d.accept();return d.result()
    with patch.object(w.dialog_types['closing'],'exec',count),patch.object(w,'show_z_report'),patch.object(TouchMessage,'information'):
        w.close_current_shift()
    assert api.post.call_count==1
    payload=api.post.call_args.args[1]
    assert 'preview' not in payload and 'float_kept' not in payload
    assert db.execute('SELECT status FROM shifts').fetchone()[0]=='closed'


def test_offline_float_branch_falls_back_to_local_close_without_calling_erp(scene):
    app,db,service,w,api=scene
    w.shift_policy=POLICY
    # Closure captured online object is supplied by the existing fixture.
    from types import SimpleNamespace
    from pos_python.provisioning import ProvisioningService
    from pos_python.ui import run_ui
    offline=SimpleNamespace(branch_id=1,terminal_id='TEST',profile={},online=False,
        provisioning=ProvisioningService(db,api),worker=SimpleNamespace(online=False,pending=0,stop=lambda:None))
    view=run_ui(service,online=offline,app=app)
    view.shift_id=w.shift_id;view.cashier=w.cashier;view.shift_policy=POLICY
    # ออฟไลน์: ไม่ขวางการปิดกะอีกแล้ว เปิดหน้าปิดกะแบบปกติ (ยอดในเครื่อง) แทนหน้าเงินทอนที่ต้องถาม ERP
    from PySide6.QtWidgets import QDialog
    seen=[]
    def fake_exec(dialog):
        seen.append((type(dialog).__name__, getattr(dialog,'offline_message','')))
        return QDialog.Rejected
    with patch.object(TouchMessage,'warning') as warning, patch.object(view.dialog_types['closing'],'exec',fake_exec), \
            patch.object(view,'backup_before_close'):
        view.close_current_shift()
    assert not any('ต้องต่อเน็ต' in str(c.args) for c in warning.call_args_list)
    assert seen and seen[0][0]=='CloseShiftDialog' and 'ออฟไลน์' in seen[0][1]
    api.post.assert_not_called()
    assert db.execute('SELECT status FROM shifts').fetchone()[0]=='open'
    view._allow_close=True;view.close();view.deleteLater()


def test_small_count_defaults_to_keep_all_and_rejects_fractional_coins(scene):
    app,db,service,w,api=scene
    d=w.dialog_types['float_closing'](w,FloatClose(api,POLICY,91),POLICY)
    d.amount.setText('0.25');assert cash(d.kept.text())==D('0.25')
    d.amount.setText('0');assert cash(d.kept.text())==0
    d.coin_fields[0].setText('1.5')
    assert 'จำนวนเต็ม' in d.error.text()
    assert not d.next.isEnabled()
    assert cash(d.amount.text())==0
    d.reject()


def test_negative_expected_remains_signed_and_does_not_change_deposit():
    api=Mock()
    shift=dict(report(),expected_cash='-0.25',counted_cash='0',float_kept='0',deposit_amount='0',cash_difference='0.25')
    api.post.return_value=dict(preview=True,shift=shift)
    result=FloatClose(api,POLICY,91).inspect('0','0','BAG-001')
    assert result['cash_difference']=='0.25' and result['deposit_amount']=='0'


def test_erp_close_time_without_offset_is_bangkok_time():
    """ERP closed_at '2026-11-13 22:13:05' (เวลาไทย) ต้องเก็บเป็น 15:13 UTC ไม่ใช่ 22:13 UTC"""
    from pos_python.ui import erp_local_to_utc
    assert erp_local_to_utc("2026-11-13 22:13:05").startswith("2026-11-13T15:13:05")
    assert erp_local_to_utc("2026-11-13T15:13:05+00:00").startswith("2026-11-13T15:13:05")
    assert erp_local_to_utc(None) is None
