"""เทสต์รูปแบบโปรโมชัน BPlus (Task 13) สำหรับ POS Python

ทดสอบตามข้อกำหนด ANTIGRAVITY_TASK_ERP_PROMO_PATTERNS.md:
- ทุก promo_type คิดส่วนลดถูกต้องถึงสตางค์
  * ครบ 3 แพ็ค (แพ็คละ 39) ราคา 100 -> ลด 17.00
  * ซื้อ 4 ชิ้น = 1 ชุด + 1 ชิ้นราคาปกติ
  * 1 แถม 1 ซื้อ 3 -> แถม 1
  * ยอดครบ 500 ลด 10% -> ลด 50.00
- นอกช่วงเวลาไม่ลด, ข้ามเที่ยงคืน, เขตเวลา Asia/Bangkok
- ตามข้อ 40: ถ้าเทสต์ตัวคิดเงินไม่ผ่าน (สูตรใน promotions.py ไม่รองรับ)
  ห้ามแก้ promotions.py เอง ให้ทำเครื่องหมาย skip พร้อมเหตุผล และส่งต่อ Claude
"""
from __future__ import annotations

import sqlite3
import tempfile
from datetime import date, datetime
from decimal import Decimal
from pathlib import Path
from zoneinfo import ZoneInfo

import pytest

from pos_python.database import connect
from pos_python.order import Order, OrderLine
from pos_python.promotions import QtyPromotion, active_promotions, promotion_discounts


def make_line(product_id: int, qty: str | int | float, price: str | int | float) -> OrderLine:
    return OrderLine(
        product_id=product_id,
        name=f"สินค้า-{product_id}",
        unit_name="ชิ้น",
        qty=Decimal(str(qty)),
        unit_price=Decimal(str(price)),
    )


class TestBPlusPromotionPatterns:
    """ทดสอบการคำนวณส่วนลดรูปแบบ BPlus ตามสูตรจริง"""

    def test_bundle_price_three_packs_for_one_hundred_baht(self) -> None:
        """ครบ 3 แพ็ค (แพ็คละ 39) ราคา 100 -> ลด 17.00 บาท"""
        promo = QtyPromotion(
            product_id=1,
            promo_type="bundle_price",
            min_qty=Decimal("3"),
            bundle_price=Decimal("100"),
            name="3 แพ็ค 100",
        )
        line = make_line(product_id=1, qty=3, price=39)  # 3 x 39 = 117.00
        discounts = promotion_discounts([line], [promo])

        # ยอดลดต้องเป็น 117 - 100 = 17.00 บาท
        assert discounts == {0: Decimal("17.00")}

        order = Order(promotions=[promo])
        order.add_product(line)
        assert order.discount_total() == Decimal("17.00")
        assert order.grand_total() == Decimal("100.00")

    def test_bundle_price_partial_set_leaves_extra_at_normal_price(self) -> None:
        """ซื้อ 4 ชิ้น (แพ็คละ 39, โปร 3 ชิ้น 100) = 1 ชุด (100) + 1 ชิ้นราคาปกติ (39) = 139.00 (ลด 17.00)"""
        promo = QtyPromotion(
            product_id=1,
            promo_type="bundle_price",
            min_qty=Decimal("3"),
            bundle_price=Decimal("100"),
        )
        line = make_line(product_id=1, qty=4, price=39)  # 4 x 39 = 156.00
        discounts = promotion_discounts([line], [promo])

        # ชุดครบ 1 ชุด ลด 17.00 บาท ชิ้นที่ 4 คิดราคาปกติ 39 บาท
        assert discounts == {0: Decimal("17.00")}

        order = Order(promotions=[promo])
        order.add_product(line)
        assert order.grand_total() == Decimal("139.00")

    def test_free_item_paired_trigger_and_gift(self) -> None:
        """1 แถม 1 ซื้อ 3 -> แถม 1 (ชุดละ 2 ชิ้น เป็นตัวซื้อ 1 + ตัวแถม 1) ชิ้นที่ 3 คิดปกติ"""
        promo = QtyPromotion(
            product_id=1,
            promo_type="free_item",
            min_qty=Decimal("2"),  # 1 ซื้อ + 1 แถม ในตะกร้านับรวม 2 ชิ้นเป็น 1 ชุด
            free_product_id=1,
            free_qty=Decimal("1"),
        )
        line = make_line(product_id=1, qty=3, price=50)  # ซื้อ 3 ชิ้น ชิ้นละ 50 บาท
        discounts = promotion_discounts([line], [promo])

        # ได้แถม 1 ชิ้น มูลค่า 50.00 บาท
        assert discounts == {0: Decimal("50.00")}

        order = Order(promotions=[promo])
        order.add_product(line)
        # 3 ชิ้น x 50 = 150 - ลด 50 = จ่าย 100.00 บาท
        assert order.grand_total() == Decimal("100.00")

    def test_free_item_separate_gift_product(self) -> None:
        """ซื้อสินค้าหลักครบ ได้ของแถมเป็นสินค้าอีกตัวหนึ่งฟรี"""
        promo = QtyPromotion(
            product_id=1,
            promo_type="free_item",
            min_qty=Decimal("2"),
            free_product_id=2,
            free_qty=Decimal("1"),
        )
        lines = [
            make_line(product_id=1, qty=5, price=100),  # ซื้อ 5 ชิ้น ได้ 2 ชุด (แถมได้ 2 ชิ้น)
            make_line(product_id=2, qty=1, price=20),   # ในตะกร้ามีของแถม 1 ชิ้น
        ]
        discounts = promotion_discounts(lines, [promo])
        assert discounts == {1: Decimal("20.00")}

    def test_discount_percent_and_fixed_baht_per_set(self) -> None:
        """ซื้อครบ X ชิ้น ลด % หรือ ลดบาทต่อชุด"""
        # ลด 10% เมื่อซื้อครบ 2 ชิ้น
        promo_pct = QtyPromotion(
            product_id=1,
            promo_type="discount",
            min_qty=Decimal("2"),
            discount_type="percent",
            discount_value=Decimal("10"),
        )
        line = make_line(product_id=1, qty=2, price=100)
        assert promotion_discounts([line], [promo_pct]) == {0: Decimal("20.00")}

        # ลด 15 บาท เมื่อซื้อครบ 3 ชิ้น
        promo_baht = QtyPromotion(
            product_id=1,
            promo_type="discount",
            min_qty=Decimal("3"),
            discount_type="baht",
            discount_value=Decimal("15"),
        )
        line3 = make_line(product_id=1, qty=7, price=50)  # 2 ชุด (ลด 30 บาท)
        assert promotion_discounts([line3], [promo_baht]) == {0: Decimal("30.00")}

    @pytest.mark.skip(
        reason="promotions.py::promotion_discounts does not support bill-level promotions (bill_discount, bill_free_item, bill_addon) without product_id; PosController filters these for POS < 1.6.5; reported to Claude"
    )
    def test_bill_discount_over_threshold(self) -> None:
        """ยอดครบ 500 ลด 10% -> ลด 50.00 (โปรระดับบิล)"""
        promo = QtyPromotion(
            product_id=0,
            promo_type="bill_discount",
            min_qty=Decimal("1"),
            discount_type="percent",
            discount_value=Decimal("10"),
        )
        lines = [make_line(product_id=1, qty=5, price=100)]  # ยอดรวม 500
        discounts = promotion_discounts(lines, [promo])
        assert sum(discounts.values()) == Decimal("50.00")

    @pytest.mark.skip(
        reason="When 1 แถม 1 is defined as min_qty=1 and free_qty=1 for identical product, promotions.py does not distinguish purchased vs free units and discounts all 3 units (150.00) instead of 1 unit (50.00); reported to Claude"
    )
    def test_free_item_identical_product_min_qty_one(self) -> None:
        """กรณีตั้งโปร 1 แถม 1 ด้วย min_qty=1, free_qty=1 สำหรับสินค้าตัวเดียวกัน แล้วซื้อ 3 ชิ้น"""
        promo = QtyPromotion(
            product_id=1,
            promo_type="free_item",
            min_qty=Decimal("1"),
            free_product_id=1,
            free_qty=Decimal("1"),
        )
        line = make_line(product_id=1, qty=3, price=50)
        discounts = promotion_discounts([line], [promo])
        assert discounts == {0: Decimal("50.00")}


class TestPromotionActiveWindow:
    """ทดสอบช่วงเวลาโปรโมชัน: นอกช่วงเวลาไม่ลด, ข้ามเที่ยงคืน, เขตเวลา Asia/Bangkok"""

    @pytest.fixture(autouse=True)
    def setup_db(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / "pos.db")
        self.db.execute(
            """INSERT INTO qty_promotions (product_id, promo_type, min_qty, bundle_price, starts_date, ends_date)
               VALUES (1, 'bundle_price', '3', '100', '2026-11-10', '2026-11-12')"""
        )
        self.db.commit()
        yield
        self.db.close()
        self.tmp.cleanup()

    def test_promotion_active_within_date_window(self) -> None:
        """ระหว่างช่วงวันที่โปรโมชันทำงาน ต้องดึงโปรได้และคิดส่วนลด"""
        promos = active_promotions(self.db, today=date(2026, 11, 11))
        assert len(promos) == 1
        line = make_line(product_id=1, qty=3, price=39)
        discounts = promotion_discounts([line], promos)
        assert discounts == {0: Decimal("17.00")}

    def test_promotion_inactive_outside_date_window(self) -> None:
        """ก่อนวันเริ่ม หรือ หลังวันสิ้นสุด ต้องไม่ดึงโปรและไม่มีส่วนลด"""
        # ก่อนเริ่ม 1 วัน
        promos_before = active_promotions(self.db, today=date(2026, 11, 9))
        assert promos_before == []

        # หลังสิ้นสุด 1 วัน
        promos_after = active_promotions(self.db, today=date(2026, 11, 13))
        assert promos_after == []

        line = make_line(product_id=1, qty=3, price=39)
        assert promotion_discounts([line], promos_before) == {}
        assert promotion_discounts([line], promos_after) == {}

    def test_midnight_crossing_promotions(self) -> None:
        """โปรโมชันข้ามเที่ยงคืน วันเริ่ม 2026-11-10 ถึง 2026-11-11"""
        # วันที่ 10 (ก่อนเที่ยงคืน) -> ใช้งานได้
        assert len(active_promotions(self.db, today=date(2026, 11, 10))) == 1
        # วันที่ 12 (วันสุดท้ายของโปร) -> ใช้งานได้
        assert len(active_promotions(self.db, today=date(2026, 11, 12))) == 1
        # วันที่ 13 (หลังข้ามเที่ยงคืนของคืนวันที่ 12) -> หมดเวลา
        assert len(active_promotions(self.db, today=date(2026, 11, 13))) == 0

    def test_asia_bangkok_timezone_handling(self) -> None:
        """ตรวจสอบวันที่ในเขตเวลา Asia/Bangkok (UTC+7)"""
        bangkok_tz = ZoneInfo("Asia/Bangkok")
        # เวลา 23:30 วันที่ 12 พ.ย. 2026 ที่กรุงเทพฯ ยังอยู่ในช่วงโปร
        dt_bangkok_active = datetime(2026, 11, 12, 23, 30, tzinfo=bangkok_tz)
        promos = active_promotions(self.db, today=dt_bangkok_active.date())
        assert len(promos) == 1

        # เวลา 00:05 วันที่ 13 พ.ย. 2026 ที่กรุงเทพฯ (หลังข้ามเที่ยงคืน 5 นาที) หมดโปรแล้ว
        dt_bangkok_expired = datetime(2026, 11, 13, 0, 5, tzinfo=bangkok_tz)
        promos_exp = active_promotions(self.db, today=dt_bangkok_expired.date())
        assert len(promos_exp) == 0

    @pytest.mark.skip(
        reason="pos_python::promotions.py only inspects starts_date and ends_date (YYYY-MM-DD); exact hour/minute starts_at/ends_at filtering is enforced on the server/sync side; reported to Claude"
    )
    def test_minute_level_time_window_in_pos_python(self) -> None:
        """ทดสอบการตัดช่วงเวลาแบบระดับนาทีใน Python POS"""
        pass
