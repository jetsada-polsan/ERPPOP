from __future__ import annotations

import unittest
from decimal import Decimal

from pos_python.promptpay import _crc16, compact_qr_lines, normalize_promptpay_id, promptpay_payload


class PromptPayTest(unittest.TestCase):
    def test_phone_is_normalized_to_the_promptpay_proxy(self) -> None:
        self.assertEqual(normalize_promptpay_id("081-234-5678"), ("01", "0066812345678"))

    def test_dynamic_payload_contains_amount_and_valid_crc(self) -> None:
        payload = promptpay_payload("0345560000000", Decimal("390.80"))
        self.assertIn("5406390.80", payload)
        self.assertEqual(payload[-4:], _crc16(payload[:-4]))

    def test_invalid_proxy_is_rejected(self) -> None:
        with self.assertRaisesRegex(ValueError, "PromptPay ID"):
            promptpay_payload("1234", "100")

    def test_compact_receipt_qr_fits_80mm_text_width(self) -> None:
        lines = compact_qr_lines(promptpay_payload("0812345678", "390.80"))
        self.assertTrue(lines)
        self.assertLessEqual(max(map(len, lines)), 42)


if __name__ == "__main__":
    unittest.main()
