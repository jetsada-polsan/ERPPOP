from decimal import Decimal as D
import unittest

from e2e.production_unit_coverage import coverage_plan, fractional_test_qty
from e2e.pos_smoke_production import barcode_price_error


class ProductionUnitCoverageTest(unittest.TestCase):
    def test_covers_units_and_barcode_types_without_duplicate_products(self):
        products = [
            {"id": 1, "sku_code": "A", "name_th": "A", "pos_price": 10, "barcodes": [
                {"barcode": "A1", "barcode_type": "EAN13_STANDARD", "unit_id": 1, "unit_factor": 1, "price": 10},
                {"barcode": "A5", "barcode_type": "EAN13_STANDARD", "unit_id": 5, "unit_factor": 5, "price": 45},
                {"barcode": "AC", "barcode_type": "CUSTOM", "unit_id": 1, "unit_factor": 1, "price": 10},
            ]},
            {"id": 2, "sku_code": "B", "name_th": "B", "pos_price": 3, "barcodes": [
                {"barcode": "B20", "barcode_type": "SCALE_PLU", "unit_id": 20, "unit_factor": 20, "price": 60},
            ]},
        ]
        plan = coverage_plan(products)
        lines = [line for product in plan["products"] for line in product["lines"]]
        self.assertEqual(3, plan["unit_cases"])
        self.assertEqual(["CUSTOM", "EAN13_STANDARD", "SCALE_PLU"], plan["barcode_types"])
        self.assertEqual({"A1", "A5", "AC", "B20"}, {line["barcode"] for line in lines})
        self.assertTrue(all(D(product["receive_base_qty"]) == 10 for product in plan["products"]))
        for product in plan["products"]:
            self.assertLessEqual(D(product["sold_base_qty"]), 10)

    def test_unpriced_catalog_units_fail_closed(self):
        with self.assertRaisesRegex(ValueError, "No priced representative"):
            coverage_plan([{"id": 1, "sku_code": "A", "name_th": "A", "pos_price": 10,
                            "barcodes": [{"barcode": "A5", "barcode_type": "CUSTOM", "unit_id": 5,
                                          "unit_factor": 5, "price": None}]}])

    def test_stock_candidate_subset_must_still_cover_the_full_catalog(self):
        universe = [
            {"id": 1, "sku_code": "A", "name_th": "A", "pos_price": 10, "barcodes": [
                {"barcode": "A1", "barcode_type": "CUSTOM", "unit_id": 1, "unit_factor": 1, "price": 10},
            ]},
            {"id": 2, "sku_code": "B", "name_th": "B", "pos_price": 20, "barcodes": [
                {"barcode": "B2", "barcode_type": "SCALE_PLU", "unit_id": 2, "unit_factor": 2, "price": 20},
            ]},
        ]
        with self.assertRaisesRegex(ValueError, "No priced representative"):
            coverage_plan(universe[:1], universe_products=universe)

    def test_invalid_factor_stops_plan_generation(self):
        with self.assertRaisesRegex(ValueError, "invalid unit factor"):
            coverage_plan([{"id": 1, "sku_code": "A", "name_th": "A", "pos_price": 10,
                            "barcodes": [{"barcode": "A0", "barcode_type": "CUSTOM", "unit_id": 0,
                                          "unit_factor": 0, "price": 10}]}])

    def test_fractional_test_quantity_fits_exactly_within_base_limit(self):
        factors = [D('1'), D('10'), D('20')]
        quantities = [fractional_test_qty(factor, len(factors)) for factor in factors]
        used_base = sum((factor * qty for factor, qty in zip(factors, quantities)), D(0))
        self.assertLessEqual(used_base, D('10'))
        self.assertGreater(used_base, D('7'))
        self.assertEqual(D('1'), quantities[0])
        self.assertTrue(all(qty > 0 for qty in quantities))

    def test_live_exact_barcode_lookup_must_match_product_and_price(self):
        live = [{"id": 23, "pos_price": 45, "matched_barcode": {"barcode": "PACK5"}}]
        self.assertIsNone(barcode_price_error("PACK5", 23, D('45.00'), live))
        self.assertIn("ราคาในเครื่อง", barcode_price_error("PACK5", 23, D('44'), live))
        self.assertIn("รหัสสินค้า", barcode_price_error("PACK5", 24, D('45'), live))
        self.assertIn("ค้นได้ 0", barcode_price_error("PACK5", 23, D('45'), []))


if __name__ == "__main__":
    unittest.main()
