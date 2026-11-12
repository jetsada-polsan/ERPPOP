"""ราคาลดรายสินค้า / นาทีทอง ต้องคิดที่เครื่องตอนขาย แบบเดียวกับ ERP PosPricingGuard::campaignPrice

production 12 พ.ย. 2569: เครื่อง v0.9.14 ขาย 202151 (ลด 10%) และ 202152 (นาทีทอง 30) ที่ราคาเต็ม 39
ERP ตีกลับบิล เพราะเครื่องไม่ได้เก็บกติกาแคมเปญ ใช้แต่ราคาฐาน
"""
from __future__ import annotations

import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from pathlib import Path

from pos_python.database import connect
from pos_python.provisioning import ProvisioningService
from pos_python.services import PosService
from tests.test_provisioning import CASHIERS, PING, FakeApi

ICT = timezone(timedelta(hours=7))


def product(server_id: int, sku: str, campaigns=None) -> dict:
    item = {"id": server_id, "sku_code": sku, "name_th": f"สินค้า {sku}", "unit_name": "แพ็ค", "is_vat": True,
            "pos_price": 39.0, "normal_price": 39.0, "base_pos_price": 39.0,
            "barcodes": [{"barcode": sku, "barcode_type": "CUSTOM", "unit_factor": "1", "price": 39.0}]}
    if campaigns is not None:
        item["campaigns"] = campaigns
    return item


class CampaignPriceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.db = connect(Path(tempfile.mkdtemp()) / "campaign.sqlite")
        self.api = FakeApi({"/api/pos/ping": PING, "/api/pos/cashiers": CASHIERS, "/api/pos/promotions": [],
                            "/api/pos/products": {"success": True, "products": []}})
        self.prov = ProvisioningService(self.db, self.api)
        self.pos = PosService(self.db)
        now = datetime.now(ICT)
        self.today = now.date().isoformat()
        self.window = {"from": (now - timedelta(hours=1)).isoformat(), "to": (now + timedelta(hours=1)).isoformat()}

    def sync(self, *items: dict) -> None:
        self.api.responses["/api/pos/products"] = {"success": True, "products": list(items)}
        self.prov.pull_catalog(3)

    def local_id(self, server_id: int) -> int:
        return int(self.db.execute("SELECT id FROM products WHERE server_id = ?", (server_id,)).fetchone()["id"])

    def test_percent_and_baht_price_cuts_apply_on_scan_and_tile(self) -> None:
        promo = {"discount_amount": "0", "discount_percent": "10", "starts_on": self.today, "ends_on": self.today}
        baht = {"discount_amount": "5", "discount_percent": "0", "starts_on": self.today, "ends_on": None}
        self.sync(product(1, "202151", {"promotions": [promo], "flash": []}),
                  product(2, "202076", {"promotions": [baht], "flash": []}))

        self.assertEqual(self.pos.barcode_cart_line("202151").unit_price, Decimal("35.10"))
        self.assertEqual(self.pos.barcode_cart_line("202076").unit_price, Decimal("34.00"))
        self.assertEqual(self.pos.campaign_price(self.local_id(1), Decimal("39")), Decimal("35.10"))

    def test_flash_price_only_inside_its_window_and_lowest_wins(self) -> None:
        later = {"from": (datetime.now(ICT) + timedelta(hours=2)).isoformat(),
                 "to": (datetime.now(ICT) + timedelta(hours=3)).isoformat()}
        promo = {"discount_amount": "0", "discount_percent": "10", "starts_on": self.today, "ends_on": self.today}
        self.sync(product(1, "202152", {"promotions": [], "flash": [{"price": "30", **self.window}]}),
                  product(2, "202153", {"promotions": [], "flash": [{"price": "20", **later}]}),
                  product(3, "202154", {"promotions": [promo], "flash": [{"price": "36", **self.window}]}))

        self.assertEqual(self.pos.barcode_cart_line("202152").unit_price, Decimal("30.00"))
        self.assertEqual(self.pos.barcode_cart_line("202153").unit_price, Decimal("39.00"), "ยังไม่ถึงเวลานาทีทอง")
        self.assertEqual(self.pos.barcode_cart_line("202154").unit_price, Decimal("35.10"), "เลือกราคาต่ำสุด")

    def test_expired_promotion_and_old_erp_without_campaigns(self) -> None:
        old = {"discount_amount": "0", "discount_percent": "50", "starts_on": "2000-01-01", "ends_on": "2000-01-02"}
        self.sync(product(1, "202151", {"promotions": [old], "flash": []}), product(2, "202160"))

        self.assertEqual(self.pos.barcode_cart_line("202151").unit_price, Decimal("39.00"))
        self.assertEqual(self.pos.barcode_cart_line("202160").unit_price, Decimal("39.00"))

    def test_next_sync_replaces_campaigns_so_cancelled_sale_stops(self) -> None:
        self.sync(product(1, "202152", {"promotions": [], "flash": [{"price": "30", **self.window}]}))
        self.assertEqual(self.pos.barcode_cart_line("202152").unit_price, Decimal("30.00"))

        self.sync(product(1, "202152", {"promotions": [], "flash": []}))
        self.assertEqual(self.pos.barcode_cart_line("202152").unit_price, Decimal("39.00"))


if __name__ == "__main__":
    unittest.main()
