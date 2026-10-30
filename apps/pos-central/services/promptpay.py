"""PromptPay payload helpers using the merchant profile supplied by the ERP."""

from decimal import Decimal, InvalidOperation
import re


def _tlv(tag: str, value: str) -> str:
    return f"{tag}{len(value):02d}{value}"


def _crc16(payload: str) -> str:
    crc = 0xFFFF
    for byte in payload.encode("ascii"):
        crc ^= byte << 8
        for _ in range(8):
            crc = ((crc << 1) ^ 0x1021) & 0xFFFF if crc & 0x8000 else (crc << 1) & 0xFFFF
    return f"{crc:04X}"


def promptpay_payload(merchant_ref: str, amount) -> str:
    digits = re.sub(r"\D", "", merchant_ref or "")
    if len(digits) == 10:
        tag, proxy = "01", "0066" + digits[1:]
    elif len(digits) == 13:
        tag, proxy = "02", digits
    elif len(digits) == 15:
        tag, proxy = "03", digits
    else:
        raise ValueError("ERP ยังไม่ได้ตั้งค่า PromptPay ID ที่ถูกต้อง")

    try:
        value = Decimal(str(amount)).quantize(Decimal("0.01"))
    except (InvalidOperation, TypeError, ValueError) as error:
        raise ValueError("ยอด QR ไม่ถูกต้อง") from error
    if value <= 0:
        raise ValueError("ยอด QR ต้องมากกว่า 0")

    merchant = _tlv("00", "A000000677010111") + _tlv(tag, proxy)
    body = "".join((
        _tlv("00", "01"), _tlv("01", "12"), _tlv("29", merchant),
        _tlv("58", "TH"), _tlv("53", "764"), _tlv("54", f"{value:.2f}"), "6304",
    ))
    return body + _crc16(body)
