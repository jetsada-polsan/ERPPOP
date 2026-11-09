"""Local time, payment acknowledgement and actual modal lifecycle regressions."""
from datetime import datetime, timedelta, timezone
from pathlib import Path
from decimal import Decimal
from functools import partial
from unittest.mock import patch

import pytest
from PySide6.QtCore import QTimer
from PySide6.QtWidgets import QDialog, QLabel

from pos_python import time_service
from pos_python.mock_printer import receipt_for, print_receipt
from pos_python.services import CartLine
from pos_python.sync_service import SyncService
from pos_python.touch_dialogs import TouchDialog, TextDialog, ChoiceDialog, ValueDialog
from test_payments import scene
from test_polish_089 import till, sale_fixture

BANGKOK = timezone(timedelta(hours=7), 'Asia/Bangkok')
LOCAL_TEXT = partial(time_service.local_datetime_text, tz=BANGKOK)


@pytest.mark.parametrize('value,expected', [
    ('2026-11-09T08:15:00+00:00', '2026-11-09 15:15'),
    ('2026-11-09T08:15:00Z', '2026-11-09 15:15'),
    ('2026-11-09T08:15:00', '2026-11-09 15:15'),
    ('2026-11-09T20:15:00+00:00', '2026-11-10 03:15'),
    ('2026-11-09T15:15:00+07:00', '2026-11-09 15:15'),
    (None, '-'), ('legacy', 'legacy'),
])
def test_local_time(value, expected):
    assert LOCAL_TEXT(value) == expected


@pytest.mark.parametrize('method', ['transfer', 'mixed'])
def test_checkout_click_confirms_transfer_and_sync(scene, method):
    db, service, shift = scene
    clicked = '2026-11-09T08:15:00+00:00'
    with patch.object(service, '_now', return_value=clicked):
        sale = service.checkout(document_no='091', branch_id=1, terminal_id='TEST',
            shift_id=shift, cashier_id=1, lines=[CartLine(1, Decimal(1), Decimal('100.05'))],
            payment_method=method, paid_amount=Decimal('100.05'), cash_amount=Decimal('30'),
            transfer_account_last4='0042', payment_reference='REF', sale_uuid='091')
    assert db.execute("SELECT confirmed_at FROM payments WHERE sale_id=? AND method='transfer'", (sale,)).fetchone()[0] == clicked
    class Api:
        def post(self, path, payload, **kwargs):
            assert payload['payment_confirmed'] is True
            assert payload['transfer_account_last4'] == '0042'
            return {'success': True, 'receipt_no': 'ERP-091'}
    SyncService(db, Api()).sync_sale('091')
    with patch('pos_python.receipt.local_datetime_text', LOCAL_TEXT):
        assert '2026-11-09 15:15' in receipt_for(db, sale)
        output = print_receipt(db, sale, Path(db.execute('PRAGMA database_list').fetchone()['file']).parent / 'receipts')
        assert '2026-11-09 15:15' in output.read_text(encoding='utf-8')
        assert '2026-11-09 15:15' in receipt_for(db, sale)
    assert db.execute('SELECT sale_datetime FROM sales WHERE id=?', (sale,)).fetchone()[0] == clicked


def test_history_details_and_z_use_local_time(till):
    window, service, _, app = till
    sale = sale_fixture(service)
    stamp = '2026-11-09T08:15:00Z'
    service.db.execute('UPDATE sales SET sale_datetime=? WHERE id=?', (stamp, sale))
    def choose(dialog):
        assert '2026-11-09 15:15' in dialog.choice_buttons[0].text()
        dialog.choice = sale
        return QDialog.DialogCode.Accepted
    texts = []
    def inspect(dialog):
        texts.append('\n'.join(label.text() for label in dialog.findChildren(QLabel)))
        return QDialog.DialogCode.Rejected
    with patch('pos_python.ui.local_datetime_text', LOCAL_TEXT), patch('pos_python.receipt.local_datetime_text', LOCAL_TEXT), patch.object(ChoiceDialog, 'exec', choose), patch.object(TouchDialog, 'exec', inspect):
        window.show_history()
        service.db.execute("UPDATE shifts SET status='closed', opened_at=?, closed_at=?", (stamp, '2026-11-09T09:15:00Z'))
        window.show_z_report()
    assert '2026-11-09 15:15' in texts[0]
    assert '09/11/2026 15:15' in texts[1]
    assert '09/11/2026 16:15' in texts[1]


@pytest.mark.parametrize('is_return', [False, True])
@pytest.mark.parametrize('outcome', ['success', 'pin_wrong', 'erp_rejected', 'erp_timeout', 'offline', 'cancel_reason', 'cancel_manager', 'cancel_pin'])
def test_receipt_action_modal_backdrops_clear(till, is_return, outcome):
    window, service, online, app = till
    sale = sale_fixture(service)
    online.online = outcome != 'offline'
    if outcome == 'pin_wrong':
        online.provisioning.verify_approver.side_effect = ValueError('denied')
    if outcome == 'erp_rejected':
        online.provisioning.api.post.return_value = {'success': False}
    if outcome == 'erp_timeout':
        online.provisioning.api.post.side_effect = TimeoutError('unknown result')
    original_exec = QDialog.exec
    seen = []
    def execute(dialog):
        seen.append(dialog)
        def interact():
            if isinstance(dialog, TextDialog):
                dialog.entry.setText('คิดสินค้าผิด')
                dialog.reject() if outcome == 'cancel_reason' else dialog.accept()
            elif isinstance(dialog, ChoiceDialog):
                dialog.choice = 901
                dialog.reject() if outcome == 'cancel_manager' else dialog.accept()
            elif isinstance(dialog, ValueDialog):
                # No PIN is generated or entered, only the result is mocked.
                if not dialog.pin:
                    dialog.entry.setText('0.5')
                dialog.reject() if dialog.pin and outcome == 'cancel_pin' else dialog.accept()
            else:
                dialog.accept()
        QTimer.singleShot(0, interact)
        result = original_exec(dialog)
        assert not dialog.isVisible()
        assert not dialog._backdrop.isVisible()
        return result
    parent = TouchDialog(window, 'รายละเอียดบิล')
    parent.show()
    app.processEvents()
    with patch.object(QDialog, 'exec', execute):
        window.receipt_action(sale, is_return)
    assert parent.isVisible()
    parent.reject()
    app.processEvents()
    assert not parent._backdrop.isVisible()
    assert all(not dialog._backdrop.isVisible() for dialog in seen)
    if outcome != 'success':
        assert service.db.execute('SELECT is_void FROM sales WHERE id=?', (sale,)).fetchone()[0] == 0


def test_pay_acknowledges_transfer_without_checkbox(till):
    from pos_python.order import OrderLine
    from pos_python.touch_dialogs import TouchMessage
    window, service, online, _ = till
    window.ensure_sale_session = lambda: True
    online.worker.wake = lambda: None
    window.order.add_product(OrderLine(1, 'สินค้า', 'ชิ้น', Decimal(1), Decimal(50)))
    def accept(dialog):
        dialog.set_payment_method('transfer')
        dialog.transfer_account_last4.setText('0042')
        dialog.transfer_confirmed.setChecked(False)
        dialog.confirm()
        return dialog.result()
    with patch.object(QDialog, 'exec', accept), patch.object(TouchMessage, 'information'), patch.object(service, 'checkout', wraps=service.checkout) as checkout:
        window.pay()
    assert checkout.call_args.kwargs['payment_confirmed'] is True
    assert service.db.execute("SELECT confirmed_at FROM payments WHERE method='transfer'").fetchone()[0]


def test_open_shift_and_daily_sales_display_local_time(till):
    from PySide6.QtWidgets import QTableWidget
    window, service, _, _ = till
    sale = sale_fixture(service)
    stamp = '2026-11-09T08:15:00+00:00'
    service.db.execute('UPDATE shifts SET opened_at=?', (stamp,))
    service.db.execute('UPDATE sales SET sale_datetime=? WHERE id=?', (stamp, sale))
    with patch('pos_python.ui.local_datetime_text', LOCAL_TEXT):
        shift = service.db.execute('SELECT * FROM shifts LIMIT 1').fetchone()
        dialog = window.dialog_types['existing'](window, shift)
        assert '2026-11-09 15:15' in dialog.findChild(QLabel, 'shiftSummary').text()
        dialog.reject()
        def inspect(dialog):
            table = next(table for table in dialog.findChildren(QTableWidget) if table.columnCount() == 4)
            assert table.item(0, 1).text() == '15:15'
            return QDialog.DialogCode.Rejected
        with patch.object(service.clock, 'now', return_value=datetime.fromisoformat(stamp)), patch.object(TouchDialog, 'exec', inspect):
            window.show_daily_sales()
