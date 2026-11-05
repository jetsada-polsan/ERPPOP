"""Drawer-float protocol. Server values are authoritative; no local deposit estimate."""
from decimal import Decimal, InvalidOperation


def cash(value, *, signed=False):
    try:
        amount = Decimal(str(value))
        if not amount.is_finite() or (amount < 0 and not signed) or amount != amount.quantize(Decimal('.01')):
            raise ValueError('จำนวนเงินต้องไม่ติดลบและมีทศนิยมไม่เกิน 2 ตำแหน่ง')
        return amount.quantize(Decimal('.01'))
    except InvalidOperation as error:
        raise ValueError('จำนวนเงินไม่ถูกต้อง') from error


class FloatClose:
    def __init__(self, api, policy, shift_id):
        if not supports_float(policy):
            raise ValueError('ERP รุ่นเก่าไม่รองรับ preview')
        self.api, self.policy, self.shift_id = api, policy, shift_id
        self.preview = None
        self.payload = None

    def invalidate(self, *_):
        self.preview = self.payload = None

    def inspect(self, counted, kept, reference='', note=''):
        self.invalidate()
        counted, kept = cash(counted), cash(kept)
        if kept > counted:
            raise ValueError('เงินทอนเก็บต้องไม่เกินยอดนับ')
        if len(reference) > 80 or len(note) > 500:
            raise ValueError('เลขซองไม่เกิน 80 และหมายเหตุไม่เกิน 500 ตัวอักษร')
        payload = dict(shift_id=self.shift_id, counted_cash=str(counted), float_kept=str(kept),
                       deposit_reference=reference, closing_note=note)
        response = self.api.post('/api/pos/shift/close', dict(payload, preview=True))
        if response.get('preview') is not True:
            raise ValueError('ERP ไม่ยืนยันว่าเป็นการตรวจยอด กรุณาตรวจสถานะกะก่อนทำต่อ')
        shift = response.get('shift') or {}
        # Missing values must never become zero or a local estimate.
        for key in ('expected_cash', 'counted_cash', 'float_kept', 'deposit_amount'):
            cash(shift[key], signed=key == 'expected_cash')
        if int(shift['id']) != self.shift_id or cash(shift['counted_cash']) != counted or cash(shift['float_kept']) != kept:
            raise ValueError('ERP คืนข้อมูลไม่ตรงกับยอดนับ')
        self.validate(shift, payload)
        self.payload, self.preview = payload, shift
        return shift

    def validate(self, shift, payload):
        counted, kept = cash(payload['counted_cash']), cash(payload['float_kept'])
        difference = Decimal(str(shift['cash_difference']))
        if (not difference.is_finite() or difference != difference.quantize(Decimal('.01'))
                or difference != counted - cash(shift['expected_cash'], signed=True)
                or cash(shift['deposit_amount']) + kept != counted
                or cash(shift['counted_cash']) != counted or cash(shift['float_kept']) != kept
                or (shift.get('deposit_reference') or '') != payload['deposit_reference']):
            raise ValueError('ยอด ERP ไม่สอดคล้องกัน ต้องตรวจสถานะก่อนทำต่อ')

    def close(self):
        if self.preview is None or self.payload is None:
            raise ValueError('ต้องตรวจยอดใหม่ก่อนยืนยันปิดกะ')
        payload = self.payload
        self.invalidate()  # Never retry an uncertain close automatically.
        response = self.api.post('/api/pos/shift/close', payload)
        shift = response.get('shift') or {}
        if not response.get('success') or shift.get('status') != 'closed' or int(shift['id']) != self.shift_id:
            raise ValueError('ยังยืนยันการปิดกะไม่ได้ กรุณาตรวจสถานะ ERP')
        self.validate(shift, payload)
        return shift


def supports_float(policy):
    return 'default_float' in policy and 'carried_float' in policy


def z_float_text(shift):
    labels = [('opening_cash', 'เงินทอนต้นกะ'), ('carried_float','เงินทอนยกมา'), ('opening_variance','ส่วนต่างรับกะ'),
              ('cash_drops','นำส่งระหว่างกะ'), ('expected_cash','ควรมี'), ('counted_cash','นับได้'),
              ('cash_difference','ขาด/เกิน'), ('deposit_amount','เงินนำส่ง'), ('float_kept','เงินทอนเก็บ'),
              ('cash_sales','ขายสด'), ('transfer_sales','ขายโอน'), ('card_sales','ขายบัตร'),
              ('cheque_sales','ขายเช็ค'), ('cash_in','เงินเข้า'), ('cash_payouts','เบิกจ่าย'),
              ('discount_total','ส่วนลด')]
    lines = [f"{label}: {Decimal(str(shift[key])):,.2f}" if shift.get(key) is not None else f'{label}: ไม่มีข้อมูล'
             for key, label in labels]
    lines += [f"เหตุผลรับกะ: {shift.get('opening_note') or '-'}", f"เลขซอง: {shift.get('deposit_reference') or '-'}",
              f"ยกเลิก: {shift.get('void_count', 'ไม่มีข้อมูล')} / คืน: {shift.get('return_count', 'ไม่มีข้อมูล')}",
              'ผู้ส่ง __________________', 'ผู้รับ/หัวหน้า __________________']
    return '\n'.join(lines)
