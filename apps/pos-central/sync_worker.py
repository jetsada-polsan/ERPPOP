import os

import time

import requests

import json

import hashlib
import re

import uuid

from datetime import datetime

from PySide6.QtCore import QThread, Signal

import threading

from database.models import (

    init_db, Product, ProductBarcode, QtyPromotion, Category, Sale, SaleItem, Payment, Outbox, SyncLog, PosShift, ScaleProfile

)

from sqlalchemy.orm import Session


def transfer_payment_is_confirmed(payment, payload):
    """Require the saved confirmation to match the exact ERP outbox payload."""
    last4 = payload.get('transfer_account_last4')
    return bool(
        payment
        and payment.payment_confirmed
        and isinstance(last4, str)
        and re.fullmatch(r'\d{4}', last4)
        and last4 == payment.transfer_account_last4
    )



class SyncWorker(QThread):

    # Signals to communicate with the main GUI thread

    sync_started = Signal()

    sync_finished = Signal(bool, str) # success, message

    products_updated = Signal()

    pending_count_updated = Signal(int) # emits number of unsynced bills for UI badge

    manual_sync_finished = Signal(bool, str)



    def __init__(self, db_path, api_url, pos_token, config=None):

        super().__init__()

        self.db_path = db_path

        self.api_url = api_url.rstrip('/') if api_url else 'https://erp.popstarcenter.com'

        self.pos_token = pos_token

        self.config = config or {}

        self.is_running = True

        self.force_sync_flag = False

        self.manual_sync_requested = False

        self.retry_failed_requested = False

        self._wake_event = threading.Event()

        self._consecutive_offline_count = 0
        self._last_scale_profiles_hash = None



    def _resolve_branch_and_cashier(self, session):

        branch_id = None

        cashier_id = None

        if self.config:

            branch_id = self.config.get('branch_id')

            cashier_id = self.config.get('cashier_id')

        if not branch_id or not cashier_id:

            import os, json

            candidates = [

                os.path.expanduser(r'~\AppData\Local\PopCentralPOS\config.json'),

                os.path.join(os.path.dirname(os.path.abspath(self.db_path)), 'config.json'),

                os.path.join(os.getcwd(), 'config.json'),

                'config.json'

            ]

            for cfg_path in candidates:

                if os.path.exists(cfg_path):

                    try:

                        with open(cfg_path, 'r', encoding='utf-8') as f:

                            data = json.load(f)

                            branch_id = branch_id or data.get('branch_id')

                            cashier_id = cashier_id or data.get('cashier_id')

                            if branch_id and cashier_id:

                                break

                    except Exception:

                        pass

        if not branch_id or not cashier_id:

            last_shift = session.query(PosShift).order_by(PosShift.id.desc()).first()

            if last_shift:

                branch_id = branch_id or getattr(last_shift, 'branch_id', None)

                cashier_id = cashier_id or getattr(last_shift, 'cashier_id', None)

        if not branch_id or not cashier_id:

            last_sale = session.query(Sale).filter(Sale.branch_id.isnot(None)).order_by(Sale.created_at.desc()).first()

            if last_sale:

                branch_id = branch_id or getattr(last_sale, 'branch_id', None)

                cashier_id = cashier_id or getattr(last_sale, 'cashier_salesman_id', None)

        return branch_id, cashier_id



    def stop(self, timeout_ms=47000):
        """Ask the worker loop to finish its current bounded request and join it."""
        self.is_running = False
        self._wake_event.set()
        self.quit()
        if self.isRunning() and QThread.currentThread() is not self:
            return self.wait(timeout_ms)
        return True


    def force_sync(self, retry_failed=False):

        self.force_sync_flag = True

        self.manual_sync_requested = True

        self.retry_failed_requested = self.retry_failed_requested or retry_failed

        self._wake_event.set()



    def _check_internet(self):

        # 1. First check if ERP API is reachable with auth and reasonable timeout

        headers = {

            'Authorization': f'Bearer {self.pos_token}',

            'Accept': 'application/json'

        }

        try:

            r = requests.get(f"{self.api_url}/api/pos/ping", headers=headers, timeout=6)

            if r.status_code in (200, 401, 403):

                self._consecutive_offline_count = 0

                return True

        except Exception:

            if not self.is_running:
                return False



        # 2. Check general external internet

        import socket

        try:

            socket.create_connection(("1.1.1.1", 53), timeout=3)

            self._consecutive_offline_count = 0

            return True

        except OSError:

            try:

                socket.create_connection(("8.8.8.8", 53), timeout=3)

                self._consecutive_offline_count = 0

                return True

            except OSError:

                pass



        # Tolerate a single transient timeout before reporting offline

        self._consecutive_offline_count += 1

        if self._consecutive_offline_count < 2:

            return True



        return False



    def get_pending_count(self):

        session = init_db(f'sqlite:///{self.db_path}')

        try:

            return session.query(Sale).filter(Sale.status.in_(['pending', 'failed'])).count()

        except Exception:

            return 0

        finally:

            session.close()



    def _check_catalog_version(self):

        """

        ตรวจสอบเลขเวอร์ชันแคตตาล็อกราคา/โปรโมชั่นผ่าน /api/pos/ping

        ถ้ามีการแก้ไขราคา โปรโมชั่น หรือนาทีทองบน ERP ตัวเลขเวอร์ชันจะเปลี่ยนทันที

        """

        headers = {

            'Authorization': f'Bearer {self.pos_token}',

            'Accept': 'application/json'

        }

        try:

            resp = requests.get(f'{self.api_url}/api/pos/ping', headers=headers, timeout=6)

            if resp.status_code == 200:

                data = resp.json()
                self._sync_scale_profiles(data.get('scale_profiles'))

                server_ver = data.get('catalog_version', 0)

                last_ver = getattr(self, '_last_catalog_version', None)

                if last_ver is not None and server_ver != last_ver:

                    self._last_catalog_version = server_ver

                    return True

                self._last_catalog_version = server_ver

        except Exception:

            pass

        return False



    def _sync_scale_profiles(self, profiles):
        """Replace ERP-owned scale profiles only after a successful ping."""
        if not isinstance(profiles, list):
            return False

        canonical = json.dumps(profiles, sort_keys=True, separators=(',', ':'))
        profile_hash = hashlib.sha256(canonical.encode('utf-8')).hexdigest()
        if profile_hash == self._last_scale_profiles_hash:
            return False

        session = init_db(f'sqlite:///{self.db_path}')
        try:
            session.query(ScaleProfile).filter(ScaleProfile.code.isnot(None)).delete(synchronize_session=False)
            for profile in profiles:
                code = str(profile.get('code') or '').strip()
                if not code:
                    continue
                session.add(ScaleProfile(
                    name=code,
                    code=code,
                    prefix=str(profile.get('prefix') or ''),
                    plu_length=int(profile.get('plu_length') or 0),
                    value_length=int(profile.get('value_length') or 0),
                    value_type=str(profile.get('value_type') or ''),
                    check_digit=str(profile.get('check_digit') or ''),
                    total_length=int(profile.get('total_length') or 0),
                    is_active=True,
                ))
            session.commit()
            self._last_scale_profiles_hash = profile_hash
            return True
        except Exception:
            session.rollback()
            raise
        finally:
            session.close()

    def run(self):

        loop_count = 0

        while self.is_running:

            manual_sync = self.manual_sync_requested

            retry_failed = self.retry_failed_requested

            self.manual_sync_requested = False

            self.retry_failed_requested = False

            try:

                # Update pending count badge on every loop

                pending_count = self.get_pending_count()

                self.pending_count_updated.emit(pending_count)



                if not self._check_internet():

                    if self.is_running:

                        self.sync_finished.emit(False, "No Internet Connection (ระบบออฟไลน์)")

                        if manual_sync:

                            self.manual_sync_finished.emit(False, "ไม่สามารถเชื่อมต่ออินเทอร์เน็ตได้")

                    if not self.is_running:

                        break

                    # Back off 15 seconds when offline to prevent disk write spam and CPU thrashing

                    self._wake_event.wait(15)

                    self._wake_event.clear()

                    continue



                if manual_sync or pending_count > 0:

                    self.sync_started.emit()

                if self.is_running:

                    changed = False



                    if retry_failed:

                        session = init_db(f'sqlite:///{self.db_path}')

                        try:

                            session.query(Outbox).filter(Outbox.status == 'failed').update(

                                {'status': 'pending', 'retry_count': 0}, synchronize_session=False

                            )

                            session.query(Sale).filter(Sale.status == 'failed').update(

                                {'status': 'pending'}, synchronize_session=False

                            )

                            session.commit()

                        finally:

                            session.close()



                    # Prioritize committed sales before network checks for catalog refresh.

                    sync_result = self._sync_outbox_sales()



                    # เช็ค Real-time: มีการอัปเดตราคาหรือโปรโมชั่นบน ERP หรือไม่

                    version_changed = self._check_catalog_version()



                    # Sync products ทันทีถ้าเวอร์ชันเปลี่ยน หรือกด sync หรือทุก ~10 นาที

                    is_initial_run = getattr(self, '_last_catalog_version', None) is None

                    if is_initial_run or version_changed or manual_sync or (loop_count % 60 == 0):

                        changed = self._sync_products()



                    if changed:

                        self.products_updated.emit()

                    self.sync_finished.emit(True, "Sync successful")

                    if manual_sync:

                        self.manual_sync_finished.emit(*sync_result)

            except Exception as e:

                import traceback

                error_msg = str(e)

                log_dir = os.path.dirname(self.db_path)

                try:

                    with open(os.path.join(log_dir, "sync_error.log"), "a", encoding="utf-8") as f:

                        f.write(f"[{datetime.now().isoformat()}] {traceback.format_exc()}\n")

                except Exception:

                    pass

                if self.is_running:

                    self.sync_finished.emit(False, error_msg)

                    if manual_sync:

                        self.manual_sync_finished.emit(False, error_msg)



            loop_count += 1



            # รอบรอ 10 วินาที เพื่อความเสถียรและประหยัดเน็ต/CPU

            self._wake_event.wait(10)

            self._wake_event.clear()

            if self.force_sync_flag:

                self.force_sync_flag = False

                loop_count = -1



    def _sync_products(self):

        headers = {

            'Authorization': f'Bearer {self.pos_token}',

            'Accept': 'application/json'

        }



        session = init_db(f'sqlite:///{self.db_path}')

        changed = False



        try:

            # The ERP deliberately exposes no product-creation route to POS devices.
            # Catalog masters are server-owned; local-only products stay local and
            # their sales will be held for review by ERP instead of being promoted.

            # FETCH FROM SERVER

            response = requests.get(f'{self.api_url}/api/pos/products?all=1', headers=headers, timeout=45)

            response.raise_for_status()



            products_data = response.json()



            import hashlib

            products_json_str = json.dumps(products_data, sort_keys=True)

            current_hash = hashlib.md5(products_json_str.encode('utf-8')).hexdigest()



            if getattr(self, '_last_products_hash', None) == current_hash and not changed:

                return self._sync_promotions(session, headers)

            self._last_products_hash = current_hash

            changed = True



            # 3. REPLACE LOCAL REPLICA

            session.query(ProductBarcode).delete(synchronize_session=False)

            session.query(Product).filter(Product.id > 0).delete(synchronize_session=False)



            for prod_data in products_data:

                barcode_val = ''

                if prod_data.get('barcodes'):

                    barcode_val = str(prod_data.get('barcodes')[0].get('barcode', ''))

                else:

                    barcode_val = str(prod_data.get('sku_code', ''))



                prod = Product(

                    id=prod_data['id'],

                    sku_code=str(prod_data['sku_code']),

                    name_th=prod_data['name_th'],

                    name_en=prod_data.get('name_en', ''),

                    default_price=prod_data['default_price'],

                    pos_price=prod_data.get('pos_price', prod_data['default_price']),

                    category_id=prod_data.get('product_category_id'),

                    image_url=prod_data.get('image_url'),

                    is_active=prod_data.get('is_active', True),

                    barcode=barcode_val,

                    stock_qty=prod_data.get('stock_qty'),

                    is_scale_item=bool(prod_data.get('is_weight') or prod_data.get('is_scale_item') or False),

                    is_promotion=bool(prod_data.get('is_promotion') or prod_data.get('is_flash_sale') or (prod_data.get('price_source') in ['promotion', 'flash_sale']))

                )

                session.add(prod)

                for barcode in prod_data.get('barcodes') or []:
                    code = str(barcode.get('barcode') or '').strip()
                    if not code:
                        continue
                    unit = barcode.get('unit') or {}
                    session.add(ProductBarcode(
                        barcode=code,
                        product_id=int(prod_data['id']),
                        barcode_type=barcode.get('barcode_type'),
                        unit_id=barcode.get('unit_id'),
                        unit_name=unit.get('name') or unit.get('clean_name'),
                        unit_factor=float(barcode.get('unit_factor') or 1),
                        price=float(barcode['price']) if barcode.get('price') is not None else None,
                    ))



            session.commit()



            # Record in sync_logs

            log = SyncLog(

                direction='pull',

                sync_type='products',

                record_count=len(products_data),

                status='success',

                message=f'Synced {len(products_data)} products successfully'

            )

            session.add(log)

            session.commit()

            promotions_changed = self._sync_promotions(session, headers)

            return changed or promotions_changed

        except Exception as e:

            session.rollback()

            try:

                log = SyncLog(

                    direction='pull',

                    sync_type='products',

                    record_count=0,

                    status='failed',

                    message=str(e)

                )

                session.add(log)

                session.commit()

            except Exception:

                pass

            raise e

        finally:

            session.close()


    def _sync_promotions(self, session, headers):
        """Refresh the branch's quantity promos so offline checkout matches ERP."""
        response = requests.get(
            f'{self.api_url}/api/pos/promotions',
            headers=headers,
            params={'branch_id': self.config.get('branch_id') or 0},
            timeout=30,
        )
        response.raise_for_status()
        payload = response.json()
        rows = payload.get('promotions', []) if isinstance(payload, dict) else payload
        if not isinstance(rows, list):
            raise ValueError('ERP ส่งข้อมูลโปร POS ไม่ถูกต้อง')

        normalized = []
        for row in rows:
            if not isinstance(row, dict) or not row.get('id') or not row.get('product_id'):
                continue
            normalized.append({
                'server_id': int(row['id']),
                'name': str(row.get('name') or ''),
                'promo_type': str(row.get('promo_type') or ''),
                'product_id': int(row['product_id']),
                'min_qty': str(row.get('min_qty') or '0'),
                'free_product_id': int(row['free_product_id']) if row.get('free_product_id') else None,
                'free_qty': str(row.get('free_qty') or '0'),
                'discount_type': row.get('discount_type'),
                'discount_value': str(row.get('discount_value') or '0'),
                'bundle_price': str(row.get('bundle_price') or '0'),
                'starts_date': str(row.get('starts_date')) if row.get('starts_date') else None,
                'ends_date': str(row.get('ends_date')) if row.get('ends_date') else None,
            })
        normalized.sort(key=lambda item: item['server_id'])

        fields = tuple(normalized[0].keys()) if normalized else (
            'server_id', 'name', 'promo_type', 'product_id', 'min_qty', 'free_product_id',
            'free_qty', 'discount_type', 'discount_value', 'bundle_price', 'starts_date', 'ends_date',
        )
        existing = [
            {field: getattr(row, field) for field in fields}
            for row in session.query(QtyPromotion).order_by(QtyPromotion.server_id).all()
        ]
        if existing == normalized:
            return False

        session.query(QtyPromotion).delete(synchronize_session=False)
        session.add_all([QtyPromotion(**row) for row in normalized])
        session.commit()
        return True



    def _sync_outbox_sales(self):

        headers = {

            'Authorization': f'Bearer {self.pos_token}',

            'Accept': 'application/json'

        }



        session = init_db(f'sqlite:///{self.db_path}')



        # ตรวจสอบว่ามีบิลที่ค้างส่งหรือไม่ หากไม่มีเลยให้ผ่านทันที

        pending_count = session.query(Sale).filter(Sale.status.in_(['pending', 'failed'])).count()

        pending_outbox_count = session.query(Outbox).filter(Outbox.status.in_(['pending', 'failed'])).count()

        if pending_count == 0 and pending_outbox_count == 0:

            session.close()

            return True, "ไม่มีบิลค้างส่ง"



        # ตรวจสอบกะที่เปิดอยู่บนเซิร์ฟเวอร์ ERP

        branch_id, cashier_id = self._resolve_branch_and_cashier(session)

        shift_params = {}

        if branch_id:

            shift_params['branch_id'] = branch_id

        if cashier_id:

            shift_params['cashier_id'] = cashier_id



        shift_data = None

        try:

            shift_response = requests.get(

                f'{self.api_url}/api/pos/shift',

                headers=headers,

                params=shift_params,

                timeout=20

            )

            if shift_response.status_code == 200:

                shift_data = shift_response.json().get('shift')

        except Exception as exc:

            session.close()

            return False, f"ตรวจสอบกะขายไม่สำเร็จ: {exc}"



        # หากเซิร์ฟเวอร์ยังไม่มีกะเปิดอยู่ แต่ในเครื่องมีบิลออฟไลน์หรือกะออฟไลน์ค้าง ให้เปิด/ซิงค์กะขึ้น ERP อัตโนมัติ

        if not shift_data:

            pending_local_shift = session.query(PosShift).filter(

                PosShift.sync_status == 'pending'

            ).order_by(PosShift.id.desc()).first()



            if not pending_local_shift:

                pending_local_shift = session.query(PosShift).filter(

                    PosShift.status == 'open'

                ).order_by(PosShift.id.desc()).first()



            if not pending_local_shift:
                session.close()
                return False, "ไม่มีข้อมูลกะขายและเงินทอนเริ่มต้นในเครื่อง กรุณาเปิดกะจริงก่อนส่งบิล"

            opening_cash = float(pending_local_shift.opening_cash)

            open_body = {

                'opening_cash': opening_cash,

                'opening_note': f"ซิงค์กะออฟไลน์อัตโนมัติ (Shift #{pending_local_shift.id if pending_local_shift else 'Offline'})"

            }

            if branch_id:

                open_body['branch_id'] = branch_id

            if cashier_id:

                open_body['cashier_id'] = cashier_id



            try:

                open_res = requests.post(

                    f'{self.api_url}/api/pos/shift/open',

                    headers=headers,

                    json=open_body,

                    timeout=20

                )

                if open_res.status_code == 200:

                    shift_data = open_res.json().get('shift')

                    if shift_data and pending_local_shift:

                        pending_local_shift.server_id = shift_data['id']

                        pending_local_shift.sync_status = 'synced'

                        session.commit()

            except Exception as e:

                pass



        if not shift_data:

            session.close()

            return False, "ยังไม่มีกะขายที่เปิดอยู่ใน ERP กรุณาเปิดกะแล้วลองส่งอีกครั้ง"



        shift_id = shift_data['id']

        branch_id = shift_data.get('branch_id') or branch_id

        cashier_id = shift_data.get('cashier_id') or cashier_id



        try:

            # 1. First ensure all pending sales have an Outbox entry

            pending_sales = session.query(Sale).filter(Sale.status.in_(['pending', 'failed'])).all()

            for sale in pending_sales:

                existing_outbox = session.query(Outbox).filter_by(aggregate_id=sale.local_sale_id).first()

                if not existing_outbox:

                    items = session.query(SaleItem).filter_by(sale_id=sale.local_sale_id).all()

                    payment = session.query(Payment).filter_by(sale_id=sale.local_sale_id).first()

                    method = sale.payment_method or 'cash'

                    payload = {

                        "local_sale_id": sale.local_sale_id,

                        "branch_id": branch_id,

                        "shift_id": shift_id,

                        "cashier_id": cashier_id,

                        "method": method,

                        "payment_confirmed": bool(payment and payment.payment_confirmed),

                        "cash_received": sale.received_amount,

                        "cash_amount": sale.cash_amount or (sale.total_amount if method == 'cash' else 0),

                        "transfer_amount": sale.transfer_amount or (sale.total_amount if method == 'transfer' else 0),

                        "change_amount": max(0, sale.received_amount - (sale.cash_amount or sale.total_amount)) if method in ('cash', 'mixed') else 0,

                        "is_full_tax": sale.is_full_tax,

                        "customer_name": sale.customer_name,

                        "customer_tax_id": sale.customer_tax_id,

                        "customer_address": sale.customer_address,

                        "items": [

                            {

                                "product_id": it.product_id,

                                "qty": it.qty,

                                "unit_price": it.unit_price

                            } for it in items

                        ]

                    }

                    if method in ('transfer', 'mixed'):

                        if payment and payment.transfer_account_last4:

                            payload['transfer_account_last4'] = payment.transfer_account_last4

                        if payment and payment.reference_no:

                            payload['payment_ref'] = payment.reference_no

                    outbox_entry = Outbox(

                        event_type='sale_checkout',

                        aggregate_id=sale.local_sale_id,

                        payload_json=json.dumps(payload),

                        idempotency_key=sale.idempotency_key or f"offline-sale-{sale.local_sale_id}",

                        status='pending'

                    )

                    session.add(outbox_entry)

            session.commit()



            # Legacy versions used different keys for immediate checkout and retry.

            # Reuse the immediate request key so an ambiguous timeout cannot duplicate a sale.

            legacy_entries = session.query(Outbox).filter(

                Outbox.event_type == 'sale_checkout',

                Outbox.status.in_(['pending', 'failed'])

            ).all()

            for entry in legacy_entries:

                legacy_key = f"pos-sale-{entry.aggregate_id}"

                if entry.idempotency_key != legacy_key:

                    continue

                entry.idempotency_key = f"pos-direct-{entry.aggregate_id}"

                sale = session.query(Sale).filter_by(local_sale_id=entry.aggregate_id).first()

                if sale and sale.idempotency_key == legacy_key:

                    sale.idempotency_key = entry.idempotency_key

            session.commit()



            # 2. Process outbox queue

            pending_outbox = session.query(Outbox).filter(Outbox.status.in_(['pending', 'failed'])).all()

            failed_count = 0

            for entry in pending_outbox:

                sale = session.query(Sale).filter_by(local_sale_id=entry.aggregate_id).first()

                payload = json.loads(entry.payload_json)



                if not entry.payload_prepared:
                    # Freeze server IDs before the first request so retries use the same payload.
                    if branch_id:
                        payload['branch_id'] = branch_id
                    if shift_id:
                        payload['shift_id'] = shift_id
                    if cashier_id:
                        payload['cashier_id'] = cashier_id
                    entry.payload_json = json.dumps(payload)
                    entry.payload_prepared = True
                    session.commit()

                # Payment proof must come from the operator's saved confirmation.
                # Never synthesize a confirmation or account suffix during retry.
                if payload.get('method') in ('transfer', 'mixed'):
                    payment = session.query(Payment).filter_by(sale_id=entry.aggregate_id).first()
                    last4 = payload.get('transfer_account_last4')
                    if not transfer_payment_is_confirmed(payment, payload):
                        message = 'โอนเงินยังไม่มีการยืนยันและเลขท้ายบัญชีผู้โอนที่บันทึกไว้; ให้ตรวจรายการชำระก่อนส่งบิล'
                        entry.status = 'failed'
                        entry.last_error = message
                        entry.retry_count += 1
                        if sale:
                            sale.status = 'failed'
                            sale.sync_error = message
                        session.commit()
                        failed_count += 1
                        continue

                req_headers = dict(headers)

                req_headers['Idempotency-Key'] = entry.idempotency_key



                try:

                    checkout_response = requests.post(f'{self.api_url}/api/pos/checkout', json=payload, headers=req_headers, timeout=45)



                    if checkout_response.status_code in (200, 201):

                        entry.status = 'synced'

                        entry.synced_at = datetime.utcnow()

                        if sale:

                            sale.status = 'synced'

                            sale.synced_at = datetime.utcnow()

                            sale.sync_error = None

                            resp_data = checkout_response.json()

                            if 'receipt_no' in resp_data:

                                sale.doc_number = resp_data['receipt_no']

                                sale.server_receipt_id = resp_data.get('receipt_id') or resp_data['receipt_no']



                        log = SyncLog(

                            direction='push',

                            sync_type='sale_checkout',

                            record_count=1,

                            status='success',

                            message=f"Sale {entry.aggregate_id} synced"

                        )

                        session.add(log)

                    elif 400 <= checkout_response.status_code < 500:

                        entry.status = 'failed'

                        entry.retry_count += 1

                        entry.last_error = checkout_response.text

                        if sale:

                            sale.status = 'failed'

                            sale.sync_error = checkout_response.text

                        failed_count += 1

                    else:

                        entry.retry_count += 1

                        entry.last_error = f"Server returned HTTP {checkout_response.status_code}"

                        failed_count += 1

                except Exception as ex:

                    entry.retry_count += 1

                    entry.last_error = str(ex)

                    if sale:

                        sale.sync_error = str(ex)

                    failed_count += 1



                session.commit()



            # Emit updated pending count to update badge

            updated_pending = session.query(Sale).filter(Sale.status.in_(['pending', 'failed'])).count()

            self.pending_count_updated.emit(updated_pending)

            if failed_count:

                return False, f"ส่งบิลไม่สำเร็จ {failed_count} บิล กรุณาตรวจสอบสาเหตุในรายการบิลค้าง"

            if updated_pending:

                return False, f"ยังมีบิลค้าง {updated_pending} บิล ระบบยังส่งไม่ครบ"

            return True, "ส่งบิลขึ้น ERP และได้รับการยืนยันครบแล้ว"



        except Exception as e:

            session.rollback()

            return False, str(e)

        finally:

            session.close()
