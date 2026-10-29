"""Scans keep their selling unit and price until ERP converts stock quantities."""
import tempfile
import unittest
from decimal import Decimal as D
from pathlib import Path

from pos_python.database import connect
from pos_python.order import Order, OrderLine
from pos_python.provisioning import ProvisioningService
from pos_python.services import PosService, now


class BarcodeSellingUnitsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.db = connect(Path(self.tmp.name) / 'units.sqlite')
        self.addCleanup(self.tmp.cleanup)
        self.addCleanup(self.db.close)
        self.db.execute("INSERT INTO products (id,sku,name,unit_name,price,updated_at) VALUES (1,'TEST','สินค้า','ชิ้น','10',?)", (now(),))
        self.db.execute("INSERT INTO price_versions (product_id,price,starts_at,version,synced_at) VALUES (1,'10','1970-01-01T00:00:00+00:00','catalog-current',?)", (now(),))
        self.prov = ProvisioningService(self.db, None)
        self.prov._replace_barcodes(1, [
            {'barcode': 'BASE', 'unit_factor': 1, 'price': 12, 'unit_id': 1, 'unit': {'name': 'ชิ้น'}},
            {'barcode': 'PACK', 'unit_factor': 5, 'price': 45, 'unit_id': 2, 'unit': {'name': 'แพ็คx5'}},
        ])
        self.db.commit()
        self.pos = PosService(self.db)

    def test_pack_price_is_not_overwritten_by_product_catalog_price(self):
        line = self.pos.barcode_cart_line('PACK')
        self.assertEqual(D('45'), line.unit_price)
        self.assertEqual(D('1'), line.qty)
        self.assertEqual('PACK', line.source_barcode)
        row = self.pos.lookup_barcode('PACK')
        self.assertEqual('แพ็คx5', row['barcode_unit_name'])
        self.assertEqual(2, row['barcode_unit_id'])
        self.assertEqual(D('10'), D(row['price']))
        self.assertEqual(D('45'), D(row['barcode_price']))

    def test_explicit_base_barcode_price_is_preserved(self):
        self.assertEqual(D('12'), self.pos.barcode_cart_line('BASE').unit_price)

    def test_unit_schedule_overrides_pack_price_without_using_base_schedule(self):
        for unit_id, price, version in [(None, '7', 'schedule:base'), (2, '40', 'schedule:pack')]:
            self.db.execute("INSERT INTO price_versions (product_id,unit_id,price,starts_at,version,synced_at) VALUES (1,?,?, '1970-01-01T00:00:00+00:00',?,?)", (unit_id, price, version, now()))
        line = self.pos.barcode_cart_line('PACK')
        self.assertEqual((D('40'), 'schedule:pack'), (line.unit_price, line.price_version))
        self.db.execute("DELETE FROM price_versions WHERE version='schedule:pack'")
        self.assertEqual((D('7'), 'schedule:base'),
                         (self.pos.barcode_cart_line('PACK').unit_price,
                          self.pos.barcode_cart_line('PACK').price_version))

    def test_pack_schedule_takes_precedence_over_base_schedule(self):
        for unit_id, price, version in [(None, '7', 'schedule:base'), (2, '40', 'schedule:pack')]:
            self.db.execute("INSERT INTO price_versions (product_id,unit_id,price,starts_at,version,synced_at) VALUES (1,?,?, '1970-01-01T00:00:00+00:00',?,?)", (unit_id, price, version, now()))
        line = self.pos.barcode_cart_line('PACK')
        self.assertEqual((D('40'), 'schedule:pack'), (line.unit_price, line.price_version))

    def test_unknown_pack_price_stops_instead_of_guessing_base_price(self):
        self.db.execute("UPDATE product_barcodes SET price=NULL WHERE barcode='PACK'")
        with self.assertRaisesRegex(ValueError, 'ราคาหน่วยขาย'):
            self.pos.barcode_cart_line('PACK')

    def test_unknown_or_inactive_barcode_has_no_cart_line(self):
        self.assertIsNone(self.pos.barcode_cart_line('MISSING'))
        self.db.execute('UPDATE products SET active=0')
        self.assertIsNone(self.pos.barcode_cart_line('BASE'))

    def test_equal_price_different_barcodes_are_not_merged(self):
        order = Order()
        for barcode, name in [('BASE', 'ชิ้น'), ('PACK', 'แพ็คx5')]:
            order.add_product(OrderLine(1, 'สินค้า', name, D('1'), D('10'), barcode=barcode, source_barcode=barcode))
        self.assertEqual(2, len(order.lines))
        self.assertEqual(['BASE', 'PACK'], [line.source_barcode for line in order.lines])

    def test_repeated_same_barcode_still_merges(self):
        order = Order()
        for _ in range(2):
            order.add_product(OrderLine(1, 'สินค้า', 'แพ็คx5', D('1'), D('45'), barcode='PACK', source_barcode='PACK'))
        self.assertEqual(1, len(order.lines))
        self.assertEqual(D('2'), order.lines[0].qty)
