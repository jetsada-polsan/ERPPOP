"""Thai QR Payment payload and compact receipt rendering.

The payload follows BOT PromptPay's EMV merchant-presented format.  Only the
merchant reference and amount vary; the exact payload is stored with the sale so
changing the configured account later never changes an old receipt.
"""
from __future__ import annotations

import re
from decimal import Decimal


PROMPTPAY_AID = "A000000677010111"


def _tlv(tag: str, value: str) -> str:
    return f"{tag}{len(value):02d}{value}"


def _crc16(payload: str) -> str:
    crc = 0xFFFF
    for byte in payload.encode("ascii"):
        crc ^= byte << 8
        for _ in range(8):
            crc = ((crc << 1) ^ 0x1021) & 0xFFFF if crc & 0x8000 else (crc << 1) & 0xFFFF
    return f"{crc:04X}"


def normalize_promptpay_id(value: str) -> tuple[str, str]:
    digits = re.sub(r"\D", "", value or "")
    if len(digits) == 10:
        # Thai mobile 08x... becomes the 13-digit international proxy 00668x...
        return "01", "0066" + digits[1:]
    if len(digits) == 13:
        return "02", digits
    if len(digits) == 15:
        return "03", digits
    raise ValueError("PromptPay ID ต้องเป็นเบอร์โทร 10 หลัก เลขบัตร/ภาษี 13 หลัก หรือ e-Wallet 15 หลัก")


def promptpay_payload(merchant_ref: str, amount: Decimal | str | int | float) -> str:
    proxy_tag, proxy = normalize_promptpay_id(merchant_ref)
    value = Decimal(str(amount)).quantize(Decimal("0.01"))
    if value <= 0:
        raise ValueError("ยอด QR ต้องมากกว่า 0")
    merchant = _tlv("00", PROMPTPAY_AID) + _tlv(proxy_tag, proxy)
    body = "".join([
        _tlv("00", "01"),
        _tlv("01", "12"),
        _tlv("29", merchant),
        _tlv("58", "TH"),
        _tlv("53", "764"),
        _tlv("54", f"{value:.2f}"),
        "6304",
    ])
    return body + _crc16(body)


def qr_matrix(payload: str, *, border: int = 1) -> list[list[bool]]:
    try:
        import qrcode
    except ImportError as error:
        raise RuntimeError("ยังไม่ได้ติดตั้ง qrcode สำหรับสร้าง Thai QR Payment") from error
    qr = qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_M, box_size=1, border=border)
    qr.add_data(payload)
    qr.make(fit=True)
    return qr.get_matrix()


def compact_qr_lines(payload: str) -> list[str]:
    """Compress two QR rows into one Unicode line for receipt preview/mock output."""
    matrix = qr_matrix(payload)
    if len(matrix) % 2:
        matrix.append([False] * len(matrix[0]))
    lines: list[str] = []
    for top_index in range(0, len(matrix), 2):
        top, bottom = matrix[top_index], matrix[top_index + 1]
        lines.append("".join("█" if a and b else "▀" if a else "▄" if b else " " for a, b in zip(top, bottom)))
    return lines
