"""Payment contract shared by checkout and touch UI; all amounts are Decimal."""
from decimal import Decimal, ROUND_HALF_UP
import re

LABELS = dict(cash="เงินสด", transfer="โอน / QR", mixed="เงินสด+โอน", credit_card="บัตรเครดิต", cheque="เช็ค")

def cents(value):
    amount = Decimal(str(value))
    if not amount.is_finite() or amount < 0:
        raise ValueError("ยอดเงินไม่ถูกต้อง")
    return amount.quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)

def validate_payment(method, total, paid, cash=None, last4=None, confirmed=False, reference=None):
    if method not in LABELS:
        raise ValueError("วิธีชำระไม่ถูกต้อง")
    total, paid = cents(total), cents(paid)
    if paid < total:
        raise ValueError("ยอดชำระไม่พอ")
    if method in ("transfer", "mixed"):
        if not confirmed:
            raise ValueError("กรุณาตรวจเงินเข้าก่อนออกบิล")
        if not re.fullmatch(r"[0-9]{4}", str(last4 or "")):
            raise ValueError("เลขท้ายบัญชีผู้โอนต้องเป็นตัวเลข 4 หลัก")
    # Vue requires the common reference for every non-cash method.
    if method != "cash" and not str(reference or "").strip():
        raise ValueError("กรอกเลขอ้างอิงการชำระ")
    if len(str(reference or "")) > 80:
        raise ValueError("เลขอ้างอิงยาวเกิน 80 ตัวอักษร")
    if method == "mixed":
        cash = cents(cash if cash is not None else 0)
        if not Decimal(0) < cash < total:
            raise ValueError("จ่ายผสม: เงินสดต้องมากกว่า 0 และน้อยกว่ายอดบิล")
        return [("cash", cash, Decimal("0.00")), ("transfer", cents(total-cash), Decimal("0.00"))]
    return [(method, paid if method == "cash" else total, cents(paid-total) if method == "cash" else Decimal("0.00"))]


def payload_fields(payments):
    first = payments[0]
    transfer = next((p for p in payments if p["method"] == "transfer"), None)
    mixed = len(payments) == 2 and first["method"] == "cash" and transfer is not None
    method = "mixed" if mixed else first["method"]
    result = dict(method=method, payment_ref=first["reference"], payment_confirmed=method != "cash")
    if transfer:
        result.update(transfer_account_last4=transfer["transfer_account_last4"], payment_confirmed=bool(transfer["confirmed_at"]))
    if method in ("cash", "mixed"):
        result.update(cash_received=first["amount"], change_amount=first["change_amount"])
    if mixed:
        result.update(cash_amount=first["amount"], transfer_amount=transfer["amount"])
    return result
