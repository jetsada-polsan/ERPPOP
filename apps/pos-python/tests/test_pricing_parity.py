"""Vue pricing UAT fixtures, ERP aggregate quantity and persisted checkout parity."""
from datetime import datetime, timedelta, timezone
from decimal import Decimal as D
from io import BytesIO
from urllib.error import HTTPError
from unittest.mock import patch

import pytest

from pos_python.api_client import LaravelApiError, LaravelPosClient
from pos_python.database import connect
from pos_python.order import Order, OrderLine
from pos_python.promotions import QtyPromotion, active_promotions, promotion_active, replace_promotions
from pos_python.services import PosService, now
from pos_python.settings_service import SettingsService
from pos_python.sync_service import SyncService, net_unit_price
from pos_python.receipt import render


def line(pid=1, price=100, qty=1):
    return OrderLine(pid, f"สินค้า {pid}", "ชิ้น", D(str(qty)), D(str(price)))


def promo(kind, **kwargs):
    args = dict(product_id=1, promo_type=kind, min_qty=D(1), name=kind)
    args.update({k: D(str(v)) if k in ('min_qty', 'min_amount', 'max_discount', 'discount_value', 'bundle_price', 'free_qty', 'free_price') else v for k, v in kwargs.items()})
    return QtyPromotion(**args)


def total(lines, promotions=(), manual=0):
    return Order(lines=lines, promotions=list(promotions), bill_discount=D(str(manual)))


def test_bundle_checkout_and_vat():
    order = total([line(price=40, qty=3)], [promo('bundle_price', min_qty=3, bundle_price=100)])
    assert (order.subtotal(), order.promotion_total(), order.grand_total()) == (120, 20, 100)
    assert sum(l.qty * l.unit_price - l.discount - l.promo_discount for l in order.to_cart_lines()) == 100
    assert total([line(price=107)]).vat_total(D(7)) == 7
    assert total([line(price=107)]).grand_total() - total([line(price=107)]).vat_total(D(7)) == 100


@pytest.mark.parametrize('kind,value,discount', [('baht', 10, 20), ('percent', 10, 10)])
def test_complete_sets(kind, value, discount):
    assert total([line(price=25, qty=5)], [promo('discount', min_qty=2, discount_type=kind, discount_value=value)]).promotion_total() == discount


def test_gifts_are_visible_zero_price_items():
    order = total([line(price=50, qty=4)], [promo('free_item', min_qty=2, free_product_id=2, free_qty=1, free_price=15, free_name='น้ำ')])
    gift = order.to_cart_lines()[-1]
    assert (gift.product_id, gift.qty, gift.unit_price, gift.list_price) == (2, 2, 0, 0)
    assert order.free_items()[0].name.startswith('น้ำ')
    assert order.grand_total() == 200
    assert order.adjustment_total() == 0


def test_manual_discount_changes_net_price_and_caps_after_promos():
    order = total([line(price=70)], manual=10)
    item = order.to_cart_lines()[0]
    assert order.manual_bill_discount() == 10
    assert order.grand_total() == item.unit_price - item.discount == 60
    order.bill_discount = D(100)
    assert order.manual_bill_discount() == 70
    assert order.grand_total() == 0


def test_time_window_inclusive_with_timezone():
    p = promo('bundle_price', starts_at='2026-11-02T05:00:00Z', ends_at='2026-11-03T05:00:00Z')
    for timestamp, expected in [('2026-11-02T04:59:00+00:00', False), ('2026-11-02T12:00:00+07:00', True), ('2026-11-03T05:00:00+00:00', True), ('2026-11-03T05:01:00+00:00', False)]:
        assert promotion_active(p, datetime.fromisoformat(timestamp)) == expected


@pytest.mark.parametrize('qty,expected', [(10, 950), (9, 900)])
def test_bill_threshold(qty, expected):
    assert total([line(qty=qty)], [promo('bill_discount', min_amount=1000, discount_type='baht', discount_value=50)]).grand_total() == expected


def test_bill_percent_cap():
    assert total([line(qty=10)], [promo('bill_discount', min_amount=500, discount_type='percent', discount_value=10, max_discount=60)]).grand_total() == 940


def test_bill_gift():
    order = total([line(qty=5)], [promo('bill_free_item', min_amount=500, free_product_id=2, free_qty=1, free_price=20, free_name='น้ำ')])
    assert order.grand_total() == 500
    assert order.to_cart_lines()[-1].unit_price == 0
    assert order.free_items()[0].qty == 1


def test_mix_bundle():
    assert total([line(price=40, qty=2), line(2, 35, 2)], [promo('mix_bundle', product_ids=(1, 2), min_qty=3, bundle_price=100)]).grand_total() == 135


def test_mix_free():
    assert total([line(price=40, qty=3), line(2, 30)], [promo('mix_free', product_ids=(1, 2), min_qty=3, free_qty=1)]).grand_total() == 120


@pytest.mark.parametrize('qty,expected', [(3, 369), (2, 300)])
def test_addon_excludes_own_gross(qty, expected):
    assert total([line(qty=qty), line(2, 50, 2)], [promo('bill_addon', product_id=2, min_amount=300, free_qty=1, bundle_price=19)]).grand_total() == expected


def test_combined_product_bill_manual_proportional_and_approval():
    order = total([line(price=40, qty=3), line(2, 100)], [promo('bundle_price', min_qty=3, bundle_price=100), promo('bill_discount', min_amount=200, discount_type='percent', discount_value=10)], 15)
    assert order.promotion_total() == 40
    assert order.grand_total() == 165
    assert order.adjustment_total() == 15
    cart = order.to_cart_lines()
    assert sum(l.qty * l.unit_price - l.discount - l.promo_discount for l in cart) == 165
    assert all(l.discount + l.promo_discount <= l.qty * l.unit_price for l in cart)
    assert cart[0].promo_discount == D('21.82')


def test_erp_aggregates_quantity_even_for_duplicate_scale_lines():
    order = total([line(price=40, qty=2), line(price=40, qty=2)], [promo('bundle_price', min_qty=3, bundle_price=100)])
    assert order.promotion_total() == 20
    order.promotions = [promo('free_item', min_qty=3, free_product_id=2, free_qty=1)]
    assert order.free_items()[0].qty == 1


def test_bill_threshold_is_after_product_promos_and_before_manual():
    order = total([line(price=40, qty=3)], [promo('bundle_price', min_qty=3, bundle_price=100), promo('bill_discount', min_amount=110, discount_value=10)], 10)
    assert order.grand_total() == 90


def test_snapshot_restore_clear_and_disk_restart(tmp_path):
    db = connect(tmp_path / 'local.db')
    settings = SettingsService(db)
    order = total([line(price=70)], manual=10)
    order.adjustment_approved_by = 'ผู้อนุมัติ UAT'
    settings.set_device_setting('current_order', order.snapshot())
    settings.set_device_setting('held_orders', [order.snapshot()])
    db.close()
    db = connect(tmp_path / 'local.db')
    settings = SettingsService(db)
    for raw in (settings.get_device_setting('current_order'), settings.get_device_setting('held_orders')[0]):
        restored = Order.restore(raw)
        assert restored.grand_total() == 60
        assert restored.adjustment_approved_by == 'ผู้อนุมัติ UAT'
        restored.clear()
        assert restored.bill_discount == 0
        assert restored.adjustment_approved_by is None
    db.close()


def test_upgrade_old_promotion_table_and_id_mapping(tmp_path):
    import sqlite3
    path = tmp_path / 'upgrade.db'
    db = sqlite3.connect(path)
    db.execute("CREATE TABLE qty_promotions (id INTEGER PRIMARY KEY, server_id INTEGER, name TEXT, promo_type TEXT, product_id INTEGER NOT NULL, min_qty TEXT, free_product_id INTEGER, free_qty TEXT, discount_type TEXT, discount_value TEXT, bundle_price TEXT, starts_date TEXT, ends_date TEXT)")
    db.execute("INSERT INTO qty_promotions VALUES (1, 9, 'เดิม', 'discount', 5, '1', NULL, '0', 'baht', '1', '0', NULL, NULL)")
    db.commit()
    db.close()
    db = connect(path)
    assert active_promotions(db)[0].name == 'เดิม'
    for local, server in [(5, 105), (6, 106)]:
        db.execute("INSERT INTO products(id,server_id,sku,name,unit_name,price,updated_at) VALUES (?,?,?,?,?,?,?)", (local, server, str(server), 'น้ำ', 'ชิ้น', '20', now()))
    rows = [dict(id=1, name='คละ', promo_type='mix_bundle', product_ids=[105, 106], min_qty=3, bundle_price=50), dict(id=2, name='แถม', promo_type='bill_free_item', min_qty=1, min_amount=100, free_product_id=106, free_qty=1, starts_at='2026-11-05T00:00:00+07:00', ends_at='2026-11-05T12:00:00+07:00')]
    assert replace_promotions(db, rows) == 2
    values = active_promotions(db, datetime.fromisoformat('2026-11-05T11:59:00+07:00'))
    assert values[0].product_ids == (5, 6)
    assert values[1].free_product_id == 6
    assert values[1].free_name == 'น้ำ'
    assert len(active_promotions(db, datetime.fromisoformat('2026-11-05T12:01:00+07:00'))) == 1
    db.close()


def test_upgrade_preserves_existing_promotion_column_with_different_case(tmp_path):
    import sqlite3
    path = tmp_path / 'legacy.db'
    db = sqlite3.connect(path)
    db.execute('CREATE TABLE qty_promotions (id INTEGER PRIMARY KEY, MAX_DISCOUNT TEXT)')
    db.execute("INSERT INTO qty_promotions (MAX_DISCOUNT) VALUES ('35')")
    db.commit()
    db.close()
    for _ in range(2):
        db = connect(path)
        assert db.execute('SELECT max_discount FROM qty_promotions').fetchone()[0] == '35'
        db.close()


def test_checkout_queue_receipt_gifts_manual_approval_and_retry(tmp_path):
    db = connect(tmp_path / 'local.db')
    # No credentials are created for this isolated till fixture.
    db.execute("INSERT INTO local_cashiers(id,server_id,code,name,pin_hash,synced_at) VALUES(1,101,'UAT','ทดสอบ','',?)", (now(),))
    for i in (1, 2):
        db.execute("INSERT INTO products(id,server_id,sku,name,unit_name,price,updated_at) VALUES(?,?,?,?,?,?,?)", (i, i+100, str(i), f'สินค้า {i}', 'ชิ้น', '40' if i == 1 else '15', now()))
    db.commit()
    service = PosService(db)
    shift = service.open_shift(1, 'UAT', 1, D(0))
    service.bind_server_shift(shift, 999)
    order = total([line(price=40, qty=3)], [promo('bundle_price', min_qty=3, bundle_price=100), promo('free_item', min_qty=3, free_product_id=2, free_qty=1, free_price=15)], 10)
    args = dict(document_no='UAT-1', branch_id=1, terminal_id='UAT', shift_id=shift, cashier_id=1, lines=order.to_cart_lines(), payment_method='cash', paid_amount=D(100), sale_uuid='uat-parity')
    with pytest.raises(ValueError, match='อนุมัติ'):
        service.checkout(**args)
    SettingsService(db).set_device_setting('current_order', order.snapshot())
    sale = service.checkout(**args, adjustment_approved_by='ผู้อนุมัติ UAT', clear_current_order=True)
    assert SettingsService(db).get_device_setting('current_order') is None
    assert 'สินค้า 2' in '\n'.join(render(db, sale))
    calls = []
    class Api:
        def post(self, path, payload, *, idempotency_key=None):
            calls.append((payload, idempotency_key))
            return dict(success=True, receipt_no='UAT-1')
    sync = SyncService(db, Api())
    sync.sync_sale('uat-parity')
    sync.sync_sale('uat-parity')
    payload = calls[0][0]
    assert payload == calls[1][0]
    assert calls[0][1] == calls[1][1] == 'uat-parity'
    assert payload['bill_promotions'] is True and payload['mix_promotions'] is True
    assert D(payload['manual_discount_amount']) == 10
    assert payload['discount_approved_by'] == 'ผู้อนุมัติ UAT'
    assert D(payload['items'][-1]['unit_price']) == 0
    assert abs(sum(D(i['qty']) * D(i['unit_price']) for i in payload['items']) - 90) < D('.0001')
    assert len(payload['items']) == 2
    assert payload['branch_id'] == 1
    assert payload['shift_id'] == 999
    # An older pending receipt must retain the rules used when money was taken.
    db.execute("UPDATE sales SET bill_promotions=0, mix_promotions=0 WHERE id=?", (sale,))
    db.commit()
    sync.sync_sale('uat-parity')
    assert calls[-1][0]['bill_promotions'] is False
    assert calls[-1][0]['mix_promotions'] is False
    db.close()


def test_401_is_readable_thai_without_raw_json():
    error = HTTPError('https://example.invalid', 401, 'Unauthorized', {}, BytesIO(b'{"message":"\\u0e44\\u0e21\\u0e48"}'))
    with patch('pos_python.api_client.urlopen', side_effect=error), pytest.raises(LaravelApiError) as raised:
        LaravelPosClient('https://example.invalid', '').get('/api/pos/ping')
    assert 'Token ไม่ถูกต้อง' in str(raised.value)
    assert '\\u0e' not in str(raised.value)


def test_overlapping_gifts_merge_for_erp_firstwhere():
    order = total([line(qty=3)], [promo('free_item', min_qty=3, free_product_id=2, free_qty=1, free_price=15), promo('bill_free_item', min_amount=300, free_product_id=2, free_qty=2, free_price=15)])
    assert len(order.free_items()) == 1
    assert order.free_items()[0].qty == 3
    assert len(order.to_cart_lines()) == 2
    assert order.grand_total() == 300


def test_rounding_allocations_never_overdraw_small_line():
    order = total([line(price='0.01'), line(2, '1.00', 3)], [promo('bill_discount', min_amount=0, discount_value='0.50')], '0.49')
    assert order.grand_total() == D('2.02')
    assert sum(l.qty * l.unit_price - l.discount - l.promo_discount for l in order.to_cart_lines()) == D('2.02')
    assert all(l.qty * l.unit_price >= l.discount + l.promo_discount for l in order.to_cart_lines())


def test_promotion_stays_active_on_its_last_local_day(tmp_path):
    """Production ERP sends ends_date for 6 Nov (Bangkok) as UTC midnight 2026-11-05T17:00Z.
    Comparing that text made every promotion vanish on its last day."""
    db = connect(tmp_path / 'tz.db')
    db.execute("INSERT INTO products(id,server_id,sku,name,unit_name,price,updated_at) VALUES (1,37965,'208078','ฝาโดม','แถว','20',?)", (now(),))
    row = dict(id=11, name='TEST-UAT 1', promo_type='discount', product_id=37965, min_qty=2, discount_type='baht',
               discount_value=5, starts_date=None, ends_date='2026-11-05T17:00:00.000000Z',
               starts_at=None, ends_at='2026-11-06T16:59:00.000000Z')
    assert replace_promotions(db, [row]) == 1
    bangkok = timezone(timedelta(hours=7))
    assert len(active_promotions(db, datetime(2026, 11, 6, 9, 45, tzinfo=bangkok))) == 1
    assert len(active_promotions(db, datetime(2026, 11, 6, 23, 58, tzinfo=bangkok))) == 1
    assert len(active_promotions(db, datetime(2026, 11, 7, 0, 1, tzinfo=bangkok))) == 0
    db.close()
