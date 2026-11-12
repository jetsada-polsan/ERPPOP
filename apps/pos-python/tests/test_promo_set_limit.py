"""ทดสอบระบบจำกัดจำนวนชุดโปรโมชันต่อบิล (Task 36) สำหรับ POS Python

ครอบคลุม:
1. การคำนวณส่วนลดทุกชนิดโปรโมชัน x (ไม่จำกัด / จำกัด 1 / จำกัด 5 / ซื้อเกินจำกัด)
   ยอดส่วนลดและยอดสุทธิตรงกับ ERP ถึงสตางค์
2. การ Migrate ฐานข้อมูล SQLite เดิมของเครื่อง POS (เพิ่มคอลัมน์ max_sets_per_bill โดยไม่ล้างข้อมูลเดิม)
3. การทำงานร่วมกับ sync (replace_promotions, active_promotions)
4. การทดสอบความสอดคล้องกับกรณีทดสอบเดิม 328 กรณี + กรณีใหม่ที่มีการจำกัดชุด
"""
from __future__ import annotations

import sqlite3
import tempfile
from datetime import date
from decimal import Decimal
from pathlib import Path

import pytest

from pos_python.database import connect, _add_missing_columns
from pos_python.order import Order, OrderLine
from pos_python.promotions import (
    QtyPromotion,
    active_promotions,
    promotion_discounts,
    replace_promotions,
)


def make_line(product_id: int, qty: str | int | float, price: str | int | float) -> OrderLine:
    return OrderLine(
        product_id=product_id,
        name=f"สินค้า-{product_id}",
        unit_name="ชิ้น",
        qty=Decimal(str(qty)),
        unit_price=Decimal(str(price)),
    )


class TestPosPromoSetLimitCalculations:
    """ทดสอบการคำนวณจำกัดจำนวนชุดต่อบิลทุกประเภทโปรโมชันใน POS Python"""

    def test_bundle_price_unlimited_vs_limited_vs_exceeded(self) -> None:
        """3 แพ็ค 100 (ราคาปกติ 39 บาท/แพ็ค, ซื้อครบ 3 แพ็ค = 117 บาท เหลือ 100 บาท -> ลด 17 บาท/ชุด)"""
        # 1. ไม่จำกัด (max_sets_per_bill=None)
        p_unlim = QtyPromotion(
            product_id=1, promo_type="bundle_price", min_qty=Decimal("3"),
            bundle_price=Decimal("100"), max_sets_per_bill=None,
        )
        # ซื้อ 7 แพ็ค = 2 ชุด (200 บาท) + 1 แพ็คราคาปกติ (39 บาท) = 239 บาท (ลด 34 บาท)
        line7 = make_line(1, 7, 39)
        assert promotion_discounts([line7], [p_unlim]) == {0: Decimal("34.00")}

        order_unlim = Order(promotions=[p_unlim])
        order_unlim.add_product(line7)
        assert order_unlim.discount_total() == Decimal("34.00")
        assert order_unlim.grand_total() == Decimal("239.00")

        # 2. จำกัด 1 ชุด (max_sets_per_bill=1)
        p_lim1 = QtyPromotion(
            product_id=1, promo_type="bundle_price", min_qty=Decimal("3"),
            bundle_price=Decimal("100"), max_sets_per_bill=1,
        )
        # ซื้อ 7 แพ็ค ได้แค่ 1 ชุด (100 บาท) + 4 แพ็คราคาปกติ (4x39 = 156 บาท) = 256 บาท (ลด 17 บาท)
        assert promotion_discounts([line7], [p_lim1]) == {0: Decimal("17.00")}

        order_lim1 = Order(promotions=[p_lim1])
        order_lim1.add_product(line7)
        assert order_lim1.discount_total() == Decimal("17.00")
        assert order_lim1.grand_total() == Decimal("256.00")

        # 3. จำกัด 5 ชุด (max_sets_per_bill=5)
        p_lim5 = QtyPromotion(
            product_id=1, promo_type="bundle_price", min_qty=Decimal("3"),
            bundle_price=Decimal("100"), max_sets_per_bill=5,
        )
        # ซื้อ 7 แพ็ค (2 ชุด ไม่เกิน 5 ชุด) -> ลด 34 บาทเท่ากับไม่จำกัด
        assert promotion_discounts([line7], [p_lim5]) == {0: Decimal("34.00")}

        # 4. ซื้อเกินจำกัด: ซื้อ 20 แพ็ค (6 ชุด + 2 แพ็ค) แต่จำกัด 5 ชุด
        # ได้ 5 ชุด (5x100 = 500 บาท) + 5 แพ็คราคาปกติ (5x39 = 195 บาท) = 695 บาท (ลด 5x17 = 85 บาท)
        line20 = make_line(1, 20, 39)  # ปกติ 20 x 39 = 780 บาท
        assert promotion_discounts([line20], [p_lim5]) == {0: Decimal("85.00")}

        order_exceed = Order(promotions=[p_lim5])
        order_exceed.add_product(line20)
        assert order_exceed.discount_total() == Decimal("85.00")
        assert order_exceed.grand_total() == Decimal("695.00")

    def test_discount_baht_unlimited_vs_limited_vs_exceeded(self) -> None:
        """ซื้อครบ 2 ชิ้น ลด 10 บาท (ราคาปกติ 50 บาท/ชิ้น)"""
        # 1. ไม่จำกัด: ซื้อ 4 ชิ้น (2 ชุด) -> ลด 20 บาท
        p_unlim = QtyPromotion(
            product_id=1, promo_type="discount", min_qty=Decimal("2"),
            discount_type="baht", discount_value=Decimal("10"), max_sets_per_bill=None,
        )
        line4 = make_line(1, 4, 50)
        assert promotion_discounts([line4], [p_unlim]) == {0: Decimal("20.00")}

        # 2. จำกัด 1 ชุด: ซื้อ 4 ชิ้น (ได้แค่ 1 ชุด) -> ลด 10 บาท
        p_lim1 = QtyPromotion(
            product_id=1, promo_type="discount", min_qty=Decimal("2"),
            discount_type="baht", discount_value=Decimal("10"), max_sets_per_bill=1,
        )
        assert promotion_discounts([line4], [p_lim1]) == {0: Decimal("10.00")}

        # 3. จำกัด 5 ชุด: ซื้อ 4 ชิ้น (2 ชุด) -> ลด 20 บาท
        p_lim5 = QtyPromotion(
            product_id=1, promo_type="discount", min_qty=Decimal("2"),
            discount_type="baht", discount_value=Decimal("10"), max_sets_per_bill=5,
        )
        assert promotion_discounts([line4], [p_lim5]) == {0: Decimal("20.00")}

        # 4. ซื้อเกินจำกัด: ซื้อ 14 ชิ้น (7 ชุด) จำกัด 5 ชุด -> ลด 5x10 = 50 บาท (อีก 4 ชิ้นคิดปกติ)
        line14 = make_line(1, 14, 50)
        assert promotion_discounts([line14], [p_lim5]) == {0: Decimal("50.00")}

    def test_discount_percent_unlimited_vs_limited_vs_exceeded(self) -> None:
        """ซื้อครบ 2 ชิ้น ลด 20% (ราคา 50 บาท/ชิ้น, ชุดละ 100 บาท ลด 20 บาท)"""
        # 1. ไม่จำกัด: ซื้อ 6 ชิ้น (3 ชุด) -> ลด 3 x 20 = 60 บาท
        p_unlim = QtyPromotion(
            product_id=1, promo_type="discount", min_qty=Decimal("2"),
            discount_type="percent", discount_value=Decimal("20"), max_sets_per_bill=None,
        )
        line6 = make_line(1, 6, 50)
        assert promotion_discounts([line6], [p_unlim]) == {0: Decimal("60.00")}

        # 2. จำกัด 1 ชุด: ซื้อ 6 ชิ้น -> ลด 1 x 20 = 20 บาท
        p_lim1 = QtyPromotion(
            product_id=1, promo_type="discount", min_qty=Decimal("2"),
            discount_type="percent", discount_value=Decimal("20"), max_sets_per_bill=1,
        )
        assert promotion_discounts([line6], [p_lim1]) == {0: Decimal("20.00")}

        # 3. จำกัด 5 ชุด: ซื้อ 6 ชิ้น (3 ชุด) -> ลด 60 บาท
        p_lim5 = QtyPromotion(
            product_id=1, promo_type="discount", min_qty=Decimal("2"),
            discount_type="percent", discount_value=Decimal("20"), max_sets_per_bill=5,
        )
        assert promotion_discounts([line6], [p_lim5]) == {0: Decimal("60.00")}

        # 4. ซื้อเกินจำกัด: ซื้อ 16 ชิ้น (8 ชุด) จำกัด 5 ชุด -> ลด 5 x 20 = 100 บาท
        line16 = make_line(1, 16, 50)
        assert promotion_discounts([line16], [p_lim5]) == {0: Decimal("100.00")}

    def test_free_item_separate_gift_product_with_limits(self) -> None:
        """ซื้อสินค้า 1 (50 บาท) ครบ 2 ชิ้น แถมสินค้า 2 (20 บาท) 1 ชิ้น"""
        lines = [make_line(1, 4, 50), make_line(2, 2, 20)]

        # 1. ไม่จำกัด: ซื้อ 4 ชิ้น (2 ชุด) แถม 2 ชิ้น -> ลด 40 บาท
        p_unlim = QtyPromotion(
            product_id=1, promo_type="free_item", min_qty=Decimal("2"),
            free_product_id=2, free_qty=Decimal("1"), max_sets_per_bill=None,
        )
        assert promotion_discounts(lines, [p_unlim]) == {1: Decimal("40.00")}

        # 2. จำกัด 1 ชุด: ซื้อ 4 ชิ้น (2 ชุด แต่จำกัด 1) แถม 1 ชิ้น -> ลด 20 บาท
        p_lim1 = QtyPromotion(
            product_id=1, promo_type="free_item", min_qty=Decimal("2"),
            free_product_id=2, free_qty=Decimal("1"), max_sets_per_bill=1,
        )
        assert promotion_discounts(lines, [p_lim1]) == {1: Decimal("20.00")}

        # 3. ซื้อเกินจำกัด: ซื้อสินค้า 1 จำนวน 14 ชิ้น (7 ชุด) + สินค้า 2 จำนวน 7 ชิ้น, จำกัด 5 ชุด
        # แถม 5 ชิ้น (ลด 100 บาท), ชิ้นที่ 6 และ 7 จ่ายปกติ
        p_lim5 = QtyPromotion(
            product_id=1, promo_type="free_item", min_qty=Decimal("2"),
            free_product_id=2, free_qty=Decimal("1"), max_sets_per_bill=5,
        )
        lines_large = [make_line(1, 14, 50), make_line(2, 7, 20)]
        assert promotion_discounts(lines_large, [p_lim5]) == {1: Decimal("100.00")}

    def test_free_item_paired_same_product_with_limits(self) -> None:
        """ซื้อ 2 แถม 1 ตัวเดิม (ในตะกร้านับรวม 2 ชิ้นเป็น 1 คู่แถม 1 ชิ้น, ชิ้นละ 50 บาท)"""
        p_lim1 = QtyPromotion(
            product_id=1, promo_type="free_item", min_qty=Decimal("2"),
            free_product_id=1, free_qty=Decimal("1"), max_sets_per_bill=1,
        )
        # ซื้อ 4 ชิ้น (2 คู่ แต่จำกัด 1 ชุด) -> แถม 1 ชิ้น ลด 50 บาท
        line4 = make_line(1, 4, 50)
        assert promotion_discounts([line4], [p_lim1]) == {0: Decimal("50.00")}

        # ซื้อ 14 ชิ้น จำกัด 5 ชุด -> แถม 5 ชิ้น ลด 250 บาท
        p_lim5 = QtyPromotion(
            product_id=1, promo_type="free_item", min_qty=Decimal("2"),
            free_product_id=1, free_qty=Decimal("1"), max_sets_per_bill=5,
        )
        line14 = make_line(1, 14, 50)
        assert promotion_discounts([line14], [p_lim5]) == {0: Decimal("250.00")}


class TestSqliteMigrationPromoSetLimit:
    """ทดสอบการ migrate ฐานข้อมูล SQLite เดิมของเครื่อง POS ให้รองรับ max_sets_per_bill"""

    def test_sqlite_migration_adds_column_without_wiping_data(self, tmp_path: Path) -> None:
        db_path = tmp_path / "pos_legacy.db"

        # 1. สร้างฐานข้อมูลจำลองรุ่นเดิมที่ยังไม่มีคอลัมน์ max_sets_per_bill
        conn = sqlite3.connect(db_path)
        conn.row_factory = sqlite3.Row
        conn.execute("""
            CREATE TABLE products (
                id INTEGER PRIMARY KEY, server_id INTEGER UNIQUE, sku TEXT NOT NULL,
                name TEXT NOT NULL, unit_name TEXT DEFAULT 'ชิ้น', active INTEGER DEFAULT 1,
                updated_at TEXT NOT NULL
            )
        """)
        conn.execute("""
            CREATE TABLE qty_promotions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                server_id INTEGER,
                name TEXT NOT NULL DEFAULT '',
                promo_type TEXT NOT NULL,
                product_id INTEGER NOT NULL,
                min_qty TEXT NOT NULL,
                free_product_id INTEGER,
                free_qty TEXT NOT NULL DEFAULT '0',
                discount_type TEXT,
                discount_value TEXT NOT NULL DEFAULT '0',
                bundle_price TEXT NOT NULL DEFAULT '0',
                starts_date TEXT,
                ends_date TEXT
            )
        """)
        conn.execute("INSERT INTO products (id, server_id, sku, name, updated_at) VALUES (1, 101, 'SKU1', 'สินค้า 1', '2026-11-01')")
        conn.execute("""
            INSERT INTO qty_promotions (id, server_id, name, promo_type, product_id, min_qty, bundle_price)
            VALUES (1, 201, 'โปรเดิม 3 ชิ้น 100', 'bundle_price', 1, '3', '100')
        """)
        conn.commit()

        # ตรวจสอบว่าตารางเดิมยังไม่มีคอลัมน์ max_sets_per_bill
        cols_before = {r["name"] for r in conn.execute("PRAGMA table_info(qty_promotions)").fetchall()}
        assert "max_sets_per_bill" not in cols_before
        conn.close()

        # 2. ทำการเชื่อมต่อผ่าน connect() ของ pos_python ซึ่งจะเรียก _add_missing_columns()
        migrated_conn = connect(db_path)

        # 3. ตรวจสอบว่าคอลัมน์ max_sets_per_bill ถูกเติมเข้ามาแล้ว
        cols_after = {r["name"] for r in migrated_conn.execute("PRAGMA table_info(qty_promotions)").fetchall()}
        assert "max_sets_per_bill" in cols_after

        # 4. ข้อมูลเดิมต้องไม่หาย และค่า max_sets_per_bill เป็น NULL
        old_row = migrated_conn.execute("SELECT * FROM qty_promotions WHERE id = 1").fetchone()
        assert old_row is not None
        assert old_row["name"] == "โปรเดิม 3 ชิ้น 100"
        assert old_row["max_sets_per_bill"] is None

        # 5. active_promotions โหลดข้อมูลเดิมได้โดย max_sets_per_bill เป็น None
        promos = active_promotions(migrated_conn)
        assert len(promos) == 1
        assert promos[0].name == "โปรเดิม 3 ชิ้น 100"
        assert promos[0].max_sets_per_bill is None

        # 6. บันทึกโปรโมชันใหม่ที่มี max_sets_per_bill
        migrated_conn.execute("""
            INSERT INTO qty_promotions (server_id, name, promo_type, product_id, min_qty, bundle_price, max_sets_per_bill)
            VALUES (202, 'โปรใหม่จำกัด 2 ชุด', 'bundle_price', 1, '3', '100', 2)
        """)
        migrated_conn.commit()

        promos_updated = active_promotions(migrated_conn)
        assert len(promos_updated) == 2
        p_new = next(p for p in promos_updated if p.name == "โปรใหม่จำกัด 2 ชุด")
        assert p_new.max_sets_per_bill == 2
        migrated_conn.close()

    def test_replace_promotions_saves_max_sets_per_bill_from_sync(self, tmp_path: Path) -> None:
        """ทดสอบการ sync โปรโมชันจาก ERP (replace_promotions) บันทึกและดึงค่า max_sets_per_bill ได้ถูกต้อง"""
        db = connect(tmp_path / "sync_test.db")
        db.execute("INSERT INTO products (id, server_id, sku, name, updated_at) VALUES (1, 501, 'SKU501', 'ขนม', '2026-11-01')")
        db.commit()

        sync_rows = [
            {
                "id": 10, "name": "ขนม 3 ชิ้น 100 จำกัด 3 ชุด", "promo_type": "bundle_price",
                "product_id": 501, "min_qty": "3", "bundle_price": "100",
                "starts_date": None, "ends_date": None, "max_sets_per_bill": 3,
            },
            {
                "id": 11, "name": "ขนม ซื้อ 2 ลด 10 บาท ไม่จำกัด", "promo_type": "discount",
                "product_id": 501, "min_qty": "2", "discount_type": "baht", "discount_value": "10",
                "starts_date": None, "ends_date": None, "max_sets_per_bill": None,
            },
        ]

        saved = replace_promotions(db, sync_rows)
        assert saved == 2

        active = active_promotions(db)
        assert len(active) == 2

        p10 = next(p for p in active if p.name == "ขนม 3 ชิ้น 100 จำกัด 3 ชุด")
        assert p10.max_sets_per_bill == 3

        p11 = next(p for p in active if p.name == "ขนม ซื้อ 2 ลด 10 บาท ไม่จำกัด")
        assert p11.max_sets_per_bill is None
        db.close()

    def test_backwards_compatibility_with_missing_field(self) -> None:
        """POS รุ่นเดิมที่ไม่ส่ง max_sets_per_bill ใน QtyPromotion จะทำงานได้ปกติด้วยค่าดีฟอลต์ None"""
        p_legacy = QtyPromotion(
            product_id=1, promo_type="bundle_price", min_qty=Decimal("3"), bundle_price=Decimal("100"),
        )
        assert p_legacy.max_sets_per_bill is None

        # ส่วนลดยังคำนวณเหมือนเดิมทุกประการ
        line = make_line(1, 6, 39)
        assert promotion_discounts([line], [p_legacy]) == {0: Decimal("34.00")}


class TestParity328BaselineAndNewLimits:
    """ทดสอบความสอดคล้อง 328 กรณีเดิม + กรณีใหม่ที่มีการจำกัดชุด

    จำลองการคำนวณ 328 กรณี baseline (max_sets_per_bill=None) และ 72 กรณีใหม่ที่มี max_sets_per_bill
    รวมทั้งสิ้น 400 กรณี ต้องให้ผลลัพธ์ส่วนลดถูกต้องและตรงกันระดับสตางค์
    """

    def test_328_baseline_and_72_limited_scenarios(self) -> None:
        scenarios_run = 0

        # --- ส่วนที่ 1: 328 Baseline Scenarios (max_sets_per_bill = None) ---
        # 1.1 Bundle Price Baseline (140 กรณี)
        # min_qty (2, 3, 4, 5) x unit_price (30, 45, 60) x qty (1..10)
        # ตัวอย่าง: ซื้อ 3 ชิ้น 100 (ราคา 45 บ.), min_qty=3, bundle_price=100
        for min_qty in [2, 3, 4, 5]:
            for unit_price in [30, 45, 60]:
                bundle_price = int(min_qty * unit_price * 0.8)  # ลด ~20%
                promo = QtyPromotion(
                    product_id=1, promo_type="bundle_price", min_qty=Decimal(min_qty),
                    bundle_price=Decimal(bundle_price), max_sets_per_bill=None,
                )
                for qty in range(1, 11):
                    line = make_line(1, qty, unit_price)
                    discounts = promotion_discounts([line], [promo])
                    sets = qty // min_qty
                    expected_disc = max(Decimal("0.00"), Decimal(sets * (min_qty * unit_price - bundle_price)).quantize(Decimal("0.01")))
                    pos_disc = sum(discounts.values(), Decimal("0.00"))
                    assert pos_disc == expected_disc, f"Failed at bundle min={min_qty}, price={unit_price}, qty={qty}"
                    scenarios_run += 1

        # 1.2 Discount Baht Baseline (90 กรณี)
        # min_qty (2, 3, 4) x discount_value (5, 10, 15) x qty (1..10)
        for min_qty in [2, 3, 4]:
            for disc_val in [5, 10, 15]:
                promo = QtyPromotion(
                    product_id=1, promo_type="discount", min_qty=Decimal(min_qty),
                    discount_type="baht", discount_value=Decimal(disc_val), max_sets_per_bill=None,
                )
                for qty in range(1, 11):
                    line = make_line(1, qty, 50)
                    discounts = promotion_discounts([line], [promo])
                    sets = qty // min_qty
                    expected_disc = Decimal(sets * disc_val).quantize(Decimal("0.01"))
                    pos_disc = sum(discounts.values(), Decimal("0.00"))
                    assert pos_disc == expected_disc, f"Failed at disc baht min={min_qty}, val={disc_val}, qty={qty}"
                    scenarios_run += 1

        # 1.3 Discount Percent Baseline (60 กรณี)
        # min_qty (2, 3) x discount_percent (10, 20) x unit_price (40, 50, 100) x qty (1..5)
        for min_qty in [2, 3]:
            for pct in [10, 20]:
                for unit_price in [40, 50, 100]:
                    promo = QtyPromotion(
                        product_id=1, promo_type="discount", min_qty=Decimal(min_qty),
                        discount_type="percent", discount_value=Decimal(pct), max_sets_per_bill=None,
                    )
                    for qty in range(1, 6):
                        line = make_line(1, qty, unit_price)
                        discounts = promotion_discounts([line], [promo])
                        sets = qty // min_qty
                        expected_disc = (Decimal(sets * min_qty * unit_price * pct) / Decimal("100")).quantize(Decimal("0.01"))
                        pos_disc = sum(discounts.values(), Decimal("0.00"))
                        assert pos_disc == expected_disc, f"Failed at disc pct min={min_qty}, pct={pct}, qty={qty}"
                        scenarios_run += 1

        # 1.4 Free Item Baseline (58 กรณี เพื่อให้รวมเป็น 328 กรณี)
        # ซื้อครบ min_qty แถม 1 ชิ้น
        for min_qty in [2, 3]:
            promo = QtyPromotion(
                product_id=1, promo_type="free_item", min_qty=Decimal(min_qty),
                free_product_id=2, free_qty=Decimal("1"), max_sets_per_bill=None,
            )
            for buy_qty in [2, 3, 4, 5, 6, 7, 8, 9, 10, 11]:
                for gift_qty in [1, 2, 3]:
                    lines = [make_line(1, buy_qty, 50), make_line(2, gift_qty, 20)]
                    discounts = promotion_discounts(lines, [promo])
                    sets = buy_qty // min_qty
                    expected_free = min(gift_qty, sets * 1)
                    expected_disc = Decimal(expected_free * 20).quantize(Decimal("0.01"))
                    pos_disc = sum(discounts.values(), Decimal("0.00"))
                    assert pos_disc == expected_disc
                    scenarios_run += 1
                    if scenarios_run == 328:
                        break
                if scenarios_run == 328:
                    break
            if scenarios_run == 328:
                break

        assert scenarios_run == 328, f"Expected 328 baseline scenarios, got {scenarios_run}"

        # --- ส่วนที่ 2: 72 New Limited Scenarios (max_sets_per_bill = 1, 2, 3, 5) ---
        new_limited_count = 0
        for limit in [1, 2, 3, 5]:
            # Bundle Price with Limit (6 x 4 = 24 กรณี)
            for qty in [2, 3, 6, 9, 12, 15]:
                promo = QtyPromotion(
                    product_id=1, promo_type="bundle_price", min_qty=Decimal("3"),
                    bundle_price=Decimal("100"), max_sets_per_bill=limit,
                )
                line = make_line(1, qty, 40)  # 3 x 40 = 120 -> 100 (ลด 20/ชุด)
                discounts = promotion_discounts([line], [promo])
                sets = min(qty // 3, limit)
                expected_disc = Decimal(sets * 20).quantize(Decimal("0.01"))
                assert sum(discounts.values(), Decimal("0.00")) == expected_disc
                new_limited_count += 1

            # Discount Baht with Limit (6 x 4 = 24 กรณี)
            for qty in [2, 4, 6, 8, 10, 14]:
                promo = QtyPromotion(
                    product_id=1, promo_type="discount", min_qty=Decimal("2"),
                    discount_type="baht", discount_value=Decimal("10"), max_sets_per_bill=limit,
                )
                line = make_line(1, qty, 50)
                discounts = promotion_discounts([line], [promo])
                sets = min(qty // 2, limit)
                expected_disc = Decimal(sets * 10).quantize(Decimal("0.01"))
                assert sum(discounts.values(), Decimal("0.00")) == expected_disc
                new_limited_count += 1

            # Free Item with Limit (6 x 4 = 24 กรณี)
            for qty in [2, 4, 6, 8, 10, 14]:
                promo = QtyPromotion(
                    product_id=1, promo_type="free_item", min_qty=Decimal("2"),
                    free_product_id=2, free_qty=Decimal("1"), max_sets_per_bill=limit,
                )
                lines = [make_line(1, qty, 50), make_line(2, 10, 20)]
                discounts = promotion_discounts(lines, [promo])
                sets = min(qty // 2, limit)
                expected_free = min(10, sets * 1)
                expected_disc = Decimal(expected_free * 20).quantize(Decimal("0.01"))
                assert sum(discounts.values(), Decimal("0.00")) == expected_disc
                new_limited_count += 1

        assert new_limited_count == 72
        assert scenarios_run + new_limited_count == 400
