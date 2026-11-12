"""Tests exact deterministic parity between POS promotions.py and ERP PosPricingGuard.

Reads the central shared fixture at tests/Fixtures/promo-parity.json (exact case).
Both ERP and POS read the exact same fixture byte-for-byte.
"""
from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal
import json
from pathlib import Path

import pytest

from pos_python.promotions import QtyPromotion, promotion_discounts


@dataclass
class Line:
    product_id: int
    qty: Decimal
    unit_price: Decimal
    list_price: Decimal | None = None


# ไฟล์เดียวกับที่ phpunit ฝั่ง ERP ใช้ — path ต้องตรงตัวพิมพ์ (tests/Fixtures) เพราะ CI รันบน Linux
# <repo>/apps/pos-python/tests/test_promo_parity_fixture.py -> parents[3] = <repo>
FIXTURE_PATH = Path(__file__).resolve().parents[3] / "tests" / "Fixtures" / "promo-parity.json"


def _load_fixture():
    with open(FIXTURE_PATH, encoding="utf-8") as f:
        return json.load(f)


FIXTURE_DATA = _load_fixture()
SCENARIOS = FIXTURE_DATA["scenarios"]


def test_fixture_scenario_count():
    assert len(SCENARIOS) == 121


@pytest.mark.parametrize("scenario", SCENARIOS, ids=lambda s: s["id"])
def test_promo_parity_scenario(scenario):
    p = scenario["promo"]
    promotion = QtyPromotion(
        product_id=int(p["product_id"]) if p["product_id"] is not None else 0,
        promo_type=str(p["promo_type"]),
        min_qty=Decimal(str(p["min_qty"])),
        free_product_id=int(p["free_product_id"]) if p["free_product_id"] is not None else None,
        free_qty=Decimal(str(p["free_qty"])),
        discount_type=p["discount_type"],
        discount_value=Decimal(str(p["discount_value"])),
        bundle_price=Decimal(str(p["bundle_price"])),
        name=scenario["name"],
        product_ids=tuple(int(x) for x in p["product_ids"]),
        max_sets_per_bill=int(p["max_sets_per_bill"]) if p["max_sets_per_bill"] is not None else None,
    )

    lines = [
        Line(
            product_id=int(line["product_id"]),
            qty=Decimal(str(line["qty"])),
            unit_price=Decimal(str(line["unit_price"])),
            list_price=Decimal(str(line["unit_price"])),
        )
        for line in scenario["lines"]
    ]

    discounts = promotion_discounts(lines, [promotion])
    actual_discount = sum(discounts.values(), Decimal("0"))
    formatted_actual = f"{actual_discount:.2f}"

    assert formatted_actual == scenario["expected_discount"], (
        f"Mismatch in scenario '{scenario['id']}': expected {scenario['expected_discount']}, got {formatted_actual}"
    )
