from decimal import Decimal
import unittest

from jet_pos.payments import Payment


class PaymentPayloadTest(unittest.TestCase):
    def test_cash_includes_change(self) -> None:
        payload = Payment("cash", Decimal("100"), cash_received=Decimal("120")).payload_fields()
        self.assertEqual(payload["change_amount"], 20.0)
        self.assertTrue(payload["payment_confirmed"])

    def test_transfer_requires_last_four_digits(self) -> None:
        with self.assertRaisesRegex(ValueError, "4 หลัก"):
            Payment("transfer", Decimal("100"), transfer_account_last4="123").payload_fields()
        payload = Payment("transfer", Decimal("100"), transfer_account_last4="1234", payment_ref="TRX-1").payload_fields()
        self.assertEqual(payload["transfer_account_last4"], "1234")

    def test_mixed_payment_must_equal_total(self) -> None:
        with self.assertRaisesRegex(ValueError, "รวมเท่ากับ"):
            Payment("mixed", Decimal("100"), cash_amount=Decimal("40"), transfer_amount=Decimal("50")).payload_fields()
        payload = Payment("mixed", Decimal("100"), cash_amount=Decimal("40"), transfer_amount=Decimal("60"), transfer_account_last4="4321").payload_fields()
        self.assertEqual(payload["transfer_amount"], 60.0)

    def test_mixed_payment_requires_both_methods(self) -> None:
        with self.assertRaisesRegex(ValueError, "ทั้งยอดเงินสดและยอดโอน"):
            Payment("mixed", Decimal("100"), cash_amount=Decimal("100"), transfer_amount=Decimal("0"), transfer_account_last4="4321").payload_fields()


if __name__ == "__main__":
    unittest.main()
