"""Local-only campaign acceptance test. Run from repository root:

QT_QPA_PLATFORM=offscreen PYTHONPATH=apps/pos-python python \
    apps/pos-python/e2e/pos_campaigns_to_laravel.py --report /tmp/campaign-report.json

Requires composer install and POS Python dependencies. Creates/discards a separate
SQLite ERP database, random test credentials, and loopback PHP server. Never reads
POS_E2E_URL/token/PIN or the application's .env database configuration. No PHP
application source is changed. Report contains totals, never credentials.
"""
from __future__ import annotations

import argparse
import base64
from contextlib import closing, ExitStack
import http.cookiejar
import json
import os
import re
import secrets
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from decimal import Decimal
from pathlib import Path
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, HTTPHandler, HTTPSHandler, ProxyHandler, Request, build_opener, install_opener

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / 'apps/pos-python'))
from pos_python.api_client import LaravelPosClient
from pos_python.database import connect
from pos_python.order import Order, OrderLine
from pos_python.promotions import active_promotions
from pos_python.provisioning import ProvisioningService
from pos_python.services import PosService, money
from pos_python.sync_service import SyncService


def local_url(url):
    parsed = urlsplit(url)
    if parsed.scheme != 'http' or parsed.hostname != '127.0.0.1' or parsed.username or parsed.password:
        raise ValueError('Campaign E2E permits only literal http://127.0.0.1 loopback')
    return url


class LoopbackOnly(HTTPHandler, HTTPSHandler):
    def http_request(self, request):
        local_url(request.full_url)
        return super().http_request(request)

    https_request = http_request


class NoExternalRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        local_url(newurl)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def run(report):
    results = []
    with tempfile.TemporaryDirectory(prefix='pos-campaigns-') as folder, ExitStack() as cleanup:
        # Disable proxies and reject off-machine requests/redirects, including API calls.
        install_opener(build_opener(ProxyHandler({}), LoopbackOnly(), NoExternalRedirect()))
        scratch = Path(folder)
        erp_path = scratch / 'erp.sqlite'
        erp_path.touch()
        env = {**os.environ, 'APP_ENV': 'testing', 'APP_DEBUG': 'false',
               'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
               'DB_CONNECTION': 'sqlite', 'DB_DATABASE': str(erp_path),
               'DB_URL': '', 'SESSION_DRIVER': 'database', 'CACHE_STORE': 'array',
               'QUEUE_CONNECTION': 'sync', 'MAIL_MAILER': 'array',
               'BCRYPT_ROUNDS': '4', 'QT_QPA_PLATFORM': 'offscreen',
               'APP_CONFIG_CACHE': str(scratch / 'config.php')}
        (scratch / '.env').write_text('APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE=' + str(erp_path) + '\n')

        def artisan(*args):
            return subprocess.run(['php', 'artisan', *args], cwd=ROOT, env=env,
                                  check=True, capture_output=True, text=True).stdout

        def php(code, data=None):
            source = ('require "vendor/autoload.php"; $app=require "bootstrap/app.php"; '
                      '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); '
                      '$input=json_decode(stream_get_contents(STDIN),true); ' + code)
            proc = subprocess.run(['php', '-r', source], cwd=ROOT, env=env,
                                  input=json.dumps(data), capture_output=True, text=True)
            if proc.returncode:
                raise RuntimeError('Local PHP fixture failed: ' + proc.stderr[-3000:] + proc.stdout[-1000:])
            return json.loads(proc.stdout)

        artisan('migrate', '--force', '--no-interaction')
        # Existing fixture seeds inventory lots, GL accounts, branch and prices.
        php('ob_start(); require "tools/staging/seed_uat_data.php"; ob_end_clean(); echo "{}";')
        fixture = php(r'''
            $branch=App\Models\Branch::where('code','UAT')->firstOrFail();
            $password=bin2hex(random_bytes(24)); $pin=(string)random_int(100000,999999);
            $user=App\Models\User::create(['username'=>'campaign_e2e','name'=>'Campaign E2E',
                'password'=>bcrypt($password),'is_active'=>true,'branch_id'=>$branch->id]);
            $user->roles()->sync(App\Models\Role::pluck('id')->all());
            $salesman=App\Models\Salesman::create(['code'=>'CAMP-E2E','name'=>'Campaign cashier',
                'branch_id'=>$branch->id,'user_id'=>$user->id,'is_active'=>true]);
            $salesman->setPin($pin,false);
            $user->forceFill(['salesman_id'=>$salesman->id])->save();
            $terminal=App\Models\PosTerminal::create(['code'=>'CAMP-E2E','name'=>'Campaign E2E','branch_id'=>$branch->id]);
            [, $token]=App\Models\PosDevice::issue(['name'=>'Campaign E2E','user_id'=>$user->id,
                'branch_id'=>$branch->id,'terminal_code'=>$terminal->code]);
            $ids=App\Models\Product::where('sku_code','like','UAT-SKU-%')->orderBy('sku_code')->limit(4)->pluck('id')->all();
            foreach ($ids as $i=>$id) { App\Models\Product::findOrFail($id)->update(['default_price'=>[50,40,20,1][$i], 'is_vat'=>[true,false,false,true][$i]]); }
            $other=App\Models\Branch::create(['code'=>'CAMP-OTHER','name_th'=>'Other test branch','is_active'=>true]);
            echo json_encode(compact('password','pin','token','ids')+['branch'=>$branch->id,'other'=>$other->id]);
        ''')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        base = local_url(f'http://127.0.0.1:{port}')
        env['APP_URL'] = base
        env['LARAVEL_PUBLIC_PATH'] = str(ROOT / 'public')
        with (scratch / 'server.log').open('w') as log:
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(ROOT / 'public'),
                                       str(ROOT / 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
                                      cwd=ROOT / 'public', env=env, stdout=log, stderr=log)
            try:
                browser = build_opener(ProxyHandler({}), LoopbackOnly(), HTTPCookieProcessor(http.cookiejar.CookieJar()), NoExternalRedirect())
                def web(path, fields=None):
                    req = Request(base + path, data=urlencode(fields, doseq=True).encode() if fields is not None else None)
                    with browser.open(req, timeout=20) as response:
                        return response.read().decode(), response.url
                for _ in range(100):
                    try:
                        html, _ = web('/login')
                        break
                    except OSError:
                        if server.poll() is not None:
                            raise RuntimeError('Local server stopped')
                        time.sleep(.1)
                else:
                    raise RuntimeError('Local server did not start')
                csrf_match = re.search(r'name="_token"\s+value="([^"]+)"', html)
                assert csrf_match, html[:1500]
                csrf = csrf_match.group(1)
                web('/login', {'_token': csrf, 'username': 'campaign_e2e', 'password': fixture['password']})
                html, url = web('/qty-promotions')
                assert url.endswith('/qty-promotions'), 'Backoffice login/permission failed'
                csrf_match = re.search(r'name="_token"\s+value="([^"]+)"', html)
                assert csrf_match, html[:1500]
                csrf = csrf_match.group(1)
                erp = cleanup.enter_context(closing(sqlite3.connect(erp_path)))
                erp.row_factory = sqlite3.Row
                db = cleanup.enter_context(closing(connect(scratch / 'pos.sqlite')))
                api = LaravelPosClient(base, fixture['token'])
                prov, pos, sync = ProvisioningService(db, api), PosService(db), SyncService(db, api)
                branch = fixture['branch']
                prov.ping()
                prov.sync_down(branch)
                login = prov.online_cashier_login(fixture['pin'])
                cashier = login['local_cashier_id']
                shift = pos.open_shift(branch, 'CAMP-E2E', cashier, Decimal('10000'))
                prov.open_server_shift(branch_id=branch, cashier_server_id=login['cashier']['id'],
                                       opening_cash=Decimal('10000'), local_shift_id=shift)
                products = [db.execute('SELECT * FROM products WHERE server_id=?', (i,)).fetchone() for i in fixture['ids']]
                a, b, gift, penny = fixture['ids']
                counter = 0

                def campaigns(specs):
                    nonlocal counter
                    # Retire prior scenarios only inside this disposable fixture database.
                    for row in erp.execute('SELECT id,code,name,promo_type FROM qty_promotions WHERE is_active=1').fetchall():
                        # Isolation between cases is fixture housekeeping, not campaign creation.
                        erp.execute('UPDATE qty_promotions SET is_active=0 WHERE id=?', (row['id'],))
                    erp.commit()
                    for spec in specs:
                        counter += 1
                        fields = {'_token': csrf, 'code': f'CAMP-{counter}', 'name': f'Campaign {counter}', 'is_active': 1, 'branch_id': branch, **spec}
                        if 'product_ids' in fields:
                            fields['product_ids[]'] = fields.pop('product_ids')
                        web('/qty-promotions', fields)
                        saved = erp.execute('SELECT id FROM qty_promotions WHERE code=?', (fields['code'],)).fetchone()
                        assert saved, f'Backoffice validation did not save {spec}'
                    prov.pull_promotions(branch)
                    return active_promotions(db)

                def sell(name, specs, basket, manual='0', approved=True, method='cash', expected_discount=None, expected_gifts=None):
                    promos = campaigns(specs)
                    lines = [OrderLine(p['id'], p['name'], p['unit_name'], Decimal(str(q)), Decimal(p['price']),
                                       is_vat=bool(p['is_vat'])) for index, q in basket for p in [products[index]]]
                    order = Order(lines=lines, promotions=promos, bill_discount=Decimal(manual))
                    cart = order.to_cart_lines()
                    if expected_gifts is not None:
                        assert sum((line.qty for line in cart if line.product_id == products[2]['id'] and line.unit_price == 0), Decimal(0)) == expected_gifts, f'{name}: missing/extra gifts'
                    before = pos.pending_sync_count()
                    try:
                        sale_id = pos.checkout(document_no=pos.next_document_no('CAMP-E2E'), branch_id=branch,
                            terminal_id='CAMP-E2E', shift_id=shift, cashier_id=cashier, lines=cart,
                            payment_method=method, paid_amount=order.grand_total(), payment_confirmed=method=='transfer',
                            transfer_account_last4='6789' if method=='transfer' else None,
                            adjustment_approved_by='Campaign supervisor' if approved else None)
                    except ValueError as error:
                        if approved or Decimal(manual) <= 0:
                            raise
                        assert "ผู้อนุมัติ" in str(error), str(error)
                        assert before == pos.pending_sync_count()
                        results.append({'case': name, 'ok': True, 'rejected': True})
                        return
                    sale = db.execute('SELECT * FROM sales WHERE id=?', (sale_id,)).fetchone()
                    sync.sync_pending_sales()
                    updated = db.execute('SELECT * FROM sales WHERE id=?', (sale_id,)).fetchone()
                    receipt = erp.execute('SELECT * FROM pos_receipts WHERE receipt_no=?', (updated['server_receipt_no'],)).fetchone()
                    errors = [dict(r) for r in db.execute('SELECT status,last_error FROM sync_outbox WHERE aggregate_uuid=?', (sale['sale_uuid'],))]
                    assert receipt, f'{name}: ERP rejected: {errors}'
                    assert updated['sync_status'] == 'synced' and receipt['status'] == 'completed'
                    # ERP gross includes catalog value of gifts, while POS displays gifts at zero.
                    gift_value = sum((Decimal(str(p.free_price))*q for p,q in order.pricing()[4]), Decimal(0))
                    pos_discount = money(Decimal(sale['discount_total']) + gift_value)
                    actual = {'net': str(money(receipt['net_sales'])), 'discount': str(money(receipt['discount_amount'])), 'vat': str(money(receipt['vat_amount']))}
                    expected = {'net': str(money(sale['grand_total'])), 'discount': str(pos_discount), 'vat': str(money(sale['vat_total']))}
                    assert money(order.grand_total()) == money(sale['grand_total'])
                    assert money(order.vat_total(pos.vat_rate())) == money(sale['vat_total'])
                    ok = actual == expected
                    items = erp.execute('SELECT * FROM pos_receipt_items WHERE pos_receipt_id=? ORDER BY seq', (receipt['id'],)).fetchall()
                    assert len(items) == len(cart), f'{name}: missing ERP items'
                    for item, line in zip(items, cart):
                        assert item['product_id'] == db.execute('SELECT server_id FROM products WHERE id=?', (line.product_id,)).fetchone()[0]
                        assert Decimal(str(item['qty'])) == line.qty
                        if line.unit_price == 0:
                            assert Decimal(str(item['unit_price'])) == 0, f'{name}: nonzero gift'
                    if method == 'transfer':
                        payment = erp.execute('SELECT * FROM pos_payments WHERE pos_receipt_id=?', (receipt['id'],)).fetchone()
                        assert payment['transfer_account_last4'] == '6789'
                    if expected_discount is not None:
                        assert money(order.promotion_total()) == money(expected_discount), (name, order.promotion_total())
                    results.append({'case': name, 'pos': expected, 'erp': actual, 'ok': ok, 'receipt': receipt['receipt_no']})
                    print(name, expected, actual, 'PASS' if ok else 'FAIL', flush=True)
                    assert ok, f'{name}: totals differ'

                definitions = [
                    ('free_item', {'product_id':a,'min_qty':3,'free_product_id':gift,'free_qty':1}),
                    ('bundle_price', {'product_id':a,'min_qty':3,'bundle_price':120}),
                    ('discount-baht', {'promo_type':'discount','product_id':a,'min_qty':3,'discount_type':'baht','discount_value':15}),
                    ('discount-percent', {'promo_type':'discount','product_id':a,'min_qty':3,'discount_type':'percent','discount_value':12.5}),
                    ('mix_bundle', {'product_ids':[a,b],'min_qty':3,'bundle_price':120}),
                    ('mix_free', {'product_ids':[a,b],'min_qty':2,'free_qty':1}),
                    ('bill_discount-percent', {'promo_type':'bill_discount','min_amount':150,'discount_type':'percent','discount_value':20,'max_discount':35}),
                    ('bill_discount-baht', {'promo_type':'bill_discount','min_amount':150,'discount_type':'baht','discount_value':17}),
                    ('bill_free_item', {'min_amount':150,'free_product_id':gift,'free_qty':1}),
                    ('bill_addon', {'min_amount':150,'product_id':gift,'bundle_price':5,'free_qty':2}),
                ]
                # Independent arithmetic expectations (display discount excludes zero-price gifts).
                discounts = {
                    'free_item': [0,0,0], 'bundle_price': [30,0,90],
                    'discount-baht': [15,0,45], 'discount-percent': ['18.75',0,'56.25'],
                    'mix_bundle': [20,0,80], 'mix_free': [40,0,140],
                    'bill_discount-percent': [30,0,35], 'bill_discount-baht': [17,0,17],
                    'bill_free_item': [0,0,0], 'bill_addon': [30,0,30],
                }
                specs_by_name = {}
                for name, fields in definitions:
                    spec = {'promo_type':name, **fields}
                    specs_by_name[name] = spec
                    is_bill = name.startswith('bill_')
                    for case_index, (tag, q) in enumerate([('exact',3), ('below',2), ('multiple',9)]):
                        basket = [(0,q)]
                        if name in ('mix_bundle', 'mix_free'):
                            basket = [(0,q-1),(1,1)]
                        if is_bill and tag == 'below':
                            basket = [(0,2),(3,49)]  # 149: one baht below 150
                        if name == 'bill_addon':
                            basket += [(2,2)]
                        sell(f'{name}/{tag}', [spec], basket, method='transfer' if tag=='multiple' else 'cash',
                             expected_discount=discounts[name][case_index],
                             expected_gifts=([1,0,3] if name=='free_item' else [1,0,1] if name=='bill_free_item' else [None]*3)[case_index])
                item = specs_by_name['discount-baht']
                bill = specs_by_name['bill_discount-percent']
                sell('mixed-vat-rounding', [specs_by_name['discount-percent']], [(0,3),(1,2),(3,7)], manual='0.13')
                sell('item+bill', [item,bill], [(0,4),(1,1)])
                sell('item+bill+manual-approved', [item,bill], [(0,4),(1,1)], manual='7.13')
                sell('item+bill+manual-unapproved', [item,bill], [(0,4),(1,1)], manual='7.13', approved=False)
                sell('duplicate-aggregate-below', [item], [(0,2),(0,7)], expected_discount=45)
                sell('duplicate-aggregate-exact', [item], [(0,3),(0,6)], expected_discount=45)
                sell('addon-excludes-self', [specs_by_name['bill_addon']], [(0,2),(3,49),(2,2)], expected_discount=0)
                sell('percent-cap', [bill], [(0,9)], expected_discount=35)
                for tag, extra in [('expired',{'starts_at':'2000-01-01','ends_at':'2001-01-01'}),
                                   ('future',{'starts_at':'2099-01-01','ends_at':'2100-01-01'}),
                                   ('disabled',{'is_active':0}), ('other-branch',{'branch_id':fixture['other']})]:
                    sell(tag, [{**item, **extra}], [(0,9)], expected_discount=0)
            finally:
                server.terminate()
                server.wait(timeout=10)
                if report:
                    Path(report).write_text(json.dumps(results, ensure_ascii=False, indent=2))
    return results


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report')
    args = parser.parse_args()
    rows = run(args.report)
    print(f'PASS {len(rows)} campaign cases')
