"""VAT ในบิล — ราคาขายรวมภาษีอยู่แล้ว การคิดผิดจึงไม่ทำให้ลูกค้าจ่ายผิด
แต่ทำให้ยอดภาษีขายที่ยื่นกรมสรรพากรผิด ซึ่งรู้ตัวช้ากว่ามาก
"""
from __future__ import annotations

import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path

from pos_python.database import connect
from pos_python.services import (
    DEFAULT_VAT_RATE,
    VAT_RATE_SETTING,
    CartLine,
    PosService,
    now,
    pin_hash,
    vat_from_inclusive,
)


class VatTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.db.execute(
            "INSERT INTO local_cashiers (id, code, name, pin_hash, synced_at) VALUES (1, 'POP001', 'แคชเชียร์', ?, ?)",
            (pin_hash("1234"), now()),
        )
        self.db.execute("INSERT INTO products (id, sku, name, unit_name, is_vat, updated_at) VALUES (1, 'P1', 'สินค้ามี VAT', 'ชิ้น', 1, ?)", (now(),))
        self.db.execute("INSERT INTO products (id, sku, name, unit_name, is_vat, updated_at) VALUES (2, 'P2', 'ผักสดยกเว้น VAT', 'กก.', 0, ?)", (now(),))
        self.db.commit()
        self.pos = PosService(self.db)
        self.shift_id = self.pos.open_shift(1, "TILL-1", 1, Decimal("0"))

    def tearDown(self) -> None:
        self.db.close()
        self.tmp.cleanup()

    def line(self, product_id: int, qty: str, price: str, discount: str = "0") -> CartLine:
        return CartLine(product_id=product_id, qty=Decimal(qty), unit_price=Decimal(price), discount=Decimal(discount))

    def sell(self, document_no: str, lines: list[CartLine], paid: str) -> int:
        return self.pos.checkout(
            document_no=document_no, branch_id=1, terminal_id="TILL-1", shift_id=self.shift_id,
            cashier_id=1, lines=lines, payment_method="cash", paid_amount=Decimal(paid),
        )

    def sale(self, sale_id: int):
        return self.db.execute("SELECT subtotal, vat_total, grand_total FROM sales WHERE id = ?", (sale_id,)).fetchone()

    def test_the_default_rate_is_the_current_thai_rate(self) -> None:
        self.assertEqual(DEFAULT_VAT_RATE, Decimal("7"))
        self.assertEqual(self.pos.vat_rate(), Decimal("7"))

    def test_vat_is_taken_out_of_the_price_not_added_on_top(self) -> None:
        sale_id = self.sell("V-001", [self.line(1, "1", "107")], "107")

        sale = self.sale(sale_id)
        # ลูกค้าจ่าย 107 เท่าเดิม ในนั้นเป็นภาษี 7 และเป็นราคาสินค้า 100
        self.assertEqual(Decimal(sale["grand_total"]), Decimal("107.00"))
        self.assertEqual(Decimal(sale["vat_total"]), Decimal("7.00"))

    def test_an_exempt_product_carries_no_vat(self) -> None:
        sale_id = self.sell("V-002", [self.line(2, "2", "50")], "100")

        sale = self.sale(sale_id)
        self.assertEqual(Decimal(sale["grand_total"]), Decimal("100.00"))
        self.assertEqual(Decimal(sale["vat_total"]), Decimal("0.00"),
                         "อาหารสดที่ยกเว้น VAT ต้องไม่ถูกคิดภาษี")

    def test_a_mixed_bill_only_taxes_the_taxable_lines(self) -> None:
        sale_id = self.sell("V-003", [self.line(1, "1", "107"), self.line(2, "1", "100")], "207")

        sale = self.sale(sale_id)
        self.assertEqual(Decimal(sale["grand_total"]), Decimal("207.00"))
        self.assertEqual(Decimal(sale["vat_total"]), Decimal("7.00"),
                         "คิด VAT ทั้งบิลจะทำให้ยอดภาษีขายที่ยื่นสูงเกินจริง")

    def test_a_discount_reduces_the_vat_with_it(self) -> None:
        sale_id = self.sell("V-004", [self.line(1, "1", "214", discount="107")], "107")

        sale = self.sale(sale_id)
        self.assertEqual(Decimal(sale["grand_total"]), Decimal("107.00"))
        self.assertEqual(Decimal(sale["vat_total"]), Decimal("7.00"),
                         "VAT ต้องคิดจากยอดหลังหักส่วนลด ไม่ใช่ราคาเต็ม")

    def test_the_rate_comes_from_the_erp_when_it_has_been_synced(self) -> None:
        self.db.execute(
            "INSERT INTO device_settings (key, value, updated_at) VALUES (?, ?, ?)",
            (VAT_RATE_SETTING, json.dumps("10"), now()),
        )
        self.db.commit()

        self.assertEqual(self.pos.vat_rate(), Decimal("10"))
        sale_id = self.sell("V-005", [self.line(1, "1", "110")], "110")
        self.assertEqual(Decimal(self.sale(sale_id)["vat_total"]), Decimal("10.00"),
                         "อัตราเปลี่ยนที่ ERP แล้วเครื่องต้องคิดตาม ไม่ต้องออกรุ่นใหม่")

    def test_a_broken_rate_setting_falls_back_instead_of_crashing_the_till(self) -> None:
        self.db.execute(
            "INSERT INTO device_settings (key, value, updated_at) VALUES (?, ?, ?)",
            (VAT_RATE_SETTING, "ไม่ใช่ตัวเลข", now()),
        )
        self.db.commit()

        # ค่าที่อ่านไม่ออกต้องไม่ทำให้ขายของไม่ได้ทั้งร้าน
        self.assertEqual(self.pos.vat_rate(), DEFAULT_VAT_RATE)

    def test_zero_rate_produces_no_vat(self) -> None:
        self.assertEqual(vat_from_inclusive(Decimal("107"), Decimal("0")), Decimal("0.00"))

    def test_the_queued_payload_tells_the_server_how_vat_was_worked_out(self) -> None:
        self.sell("V-006", [self.line(1, "1", "107")], "107")

        payload = json.loads(self.db.execute("SELECT payload FROM sync_outbox ORDER BY id DESC LIMIT 1").fetchone()["payload"])
        self.assertEqual(payload["vat_total"], "7.00")
        self.assertEqual(payload["vat_rate"], "7")
        self.assertEqual(payload["vat_mode"], "included",
                         "เซิร์ฟเวอร์ต้องรู้ว่าราคารวม VAT แล้ว ไม่งั้นจะบวกซ้ำอีกรอบ")


if __name__ == "__main__":
    unittest.main()
