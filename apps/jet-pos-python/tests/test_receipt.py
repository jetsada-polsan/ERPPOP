from decimal import Decimal
import unittest

from jet_pos.receipt import ReceiptLine, receipt_html


class ReceiptHtmlTest(unittest.TestCase):
    def test_receipt_escapes_product_content_and_shows_totals(self) -> None:
        html = receipt_html(
            "POS-0001", [ReceiptLine("SKU1", "น้ำปลา <ทดลอง>", Decimal("2"), Decimal("15.50"))], Decimal("31"), "cash", Decimal("9"), {"name": "MPM & Co."},
        )
        self.assertIn("MPM &amp; Co.", html)
        self.assertIn("น้ำปลา &lt;ทดลอง&gt;", html)
        self.assertIn("รวมสุทธิ ฿31.00", html)
        self.assertIn("เงินทอน ฿9.00", html)


if __name__ == "__main__":
    unittest.main()
