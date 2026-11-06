from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal, ROUND_HALF_UP
from typing import Literal


PaymentMethod = Literal["cash", "transfer", "credit_card", "cheque", "mixed"]
MONEY_QUANTUM = Decimal("0.01")


def money(value: Decimal | int | float | str) -> Decimal:
    return Decimal(str(value)).quantize(MONEY_QUANTUM, rounding=ROUND_HALF_UP)


@dataclass(frozen=True)
class Payment:
    method: PaymentMethod
    total: Decimal
    cash_received: Decimal = Decimal("0")
    cash_amount: Decimal = Decimal("0")
    transfer_amount: Decimal = Decimal("0")
    payment_ref: str = ""
    transfer_account_last4: str = ""

    def payload_fields(self) -> dict[str, object]:
        total = money(self.total)
        cash_received = money(self.cash_received)
        cash_amount = money(self.cash_amount)
        transfer_amount = money(self.transfer_amount)
        if total <= 0:
            raise ValueError("ยอดชำระต้องมากกว่า 0")
        if self.method == "cash":
            if cash_received < total:
                raise ValueError("รับเงินสดไม่ครบ")
            return {
                "method": "cash",
                "cash_received": float(cash_received),
                "change_amount": float(money(cash_received - total)),
                "payment_confirmed": True,
            }
        if self.method == "transfer":
            if not self.transfer_account_last4.isdigit() or len(self.transfer_account_last4) != 4:
                raise ValueError("การโอนต้องระบุเลขท้ายบัญชี 4 หลัก")
            return {
                "method": "transfer",
                "payment_ref": self.payment_ref.strip(),
                "transfer_account_last4": self.transfer_account_last4,
                "payment_confirmed": True,
            }
        if self.method in {"credit_card", "cheque"}:
            return {
                "method": self.method,
                "payment_ref": self.payment_ref.strip(),
                "payment_confirmed": True,
            }
        if self.method == "mixed":
            if cash_amount < MONEY_QUANTUM or transfer_amount < MONEY_QUANTUM:
                raise ValueError("จ่ายผสมต้องระบุทั้งยอดเงินสดและยอดโอน")
            if money(cash_amount + transfer_amount) != total:
                raise ValueError("ยอดเงินสดและยอดโอนต้องรวมเท่ากับยอดสุทธิ")
            if not self.transfer_account_last4.isdigit() or len(self.transfer_account_last4) != 4:
                raise ValueError("ส่วนที่โอนต้องระบุเลขท้ายบัญชี 4 หลัก")
            return {
                "method": "mixed",
                "cash_amount": float(cash_amount),
                "transfer_amount": float(transfer_amount),
                "payment_ref": self.payment_ref.strip(),
                "transfer_account_last4": self.transfer_account_last4 if transfer_amount > 0 else None,
                "payment_confirmed": True,
            }
        raise ValueError("ไม่รองรับวิธีชำระเงิน")
