"""Choose representative products covering every catalog selling unit and barcode type.

This module is read-only. Quantities are selling units; receipt quantities are
base units, limited to ten per product. Large units use fractional quantities.
"""
from __future__ import annotations

from decimal import Decimal, ROUND_DOWN


def fractional_test_qty(factor: Decimal, lines_in_product: int) -> Decimal:
    """Use whole selling units when they fit; otherwise cap each product at 10 base units."""
    if factor <= 0 or lines_in_product <= 0:
        raise ValueError('Invalid selling unit test case')
    full = factor * Decimal(lines_in_product)
    if full <= 10:
        return Decimal(1)
    return min(Decimal(1), (Decimal(10) / Decimal(lines_in_product) / factor).quantize(
        Decimal('.0001'), rounding=ROUND_DOWN,
    ))


def coverage_plan(products: list[dict], *, universe_products: list[dict] | None = None) -> dict:
    """Pick priced candidates while requiring coverage of the full catalog universe.

    ``universe_products`` lets a production smoke test restrict candidates to
    items already in stock without accidentally dropping a unit/barcode class
    that only appears on an out-of-stock product.
    """
    universe = set()
    candidates = []
    for product in universe_products if universe_products is not None else products:
        for barcode in product.get('barcodes', []):
            raw_factor = barcode.get('unit_factor')
            factor = Decimal(str(1 if raw_factor is None or raw_factor == '' else raw_factor))
            if not factor.is_finite() or factor <= 0:
                raise ValueError('Catalog contains an invalid unit factor')
            key = ('unit', barcode.get('unit_id'), str(factor.normalize()))
            kind = ('barcode', barcode.get('barcode_type', 'CUSTOM'))
            universe.update((key, kind))

    for product in products:
        options = []
        for barcode in product.get('barcodes', []):
            raw_factor = barcode.get('unit_factor')
            factor = Decimal(str(1 if raw_factor is None or raw_factor == '' else raw_factor))
            if not factor.is_finite() or factor <= 0:
                raise ValueError('Catalog contains an invalid unit factor')
            key = ('unit', barcode.get('unit_id'), str(factor.normalize()))
            kind = ('barcode', barcode.get('barcode_type', 'CUSTOM'))
            price = Decimal(str(barcode.get('price') or 0))
            if price <= 0 and factor == 1:
                price = Decimal(str(product.get('pos_price') or 0))
            if price > 0:
                options.append((barcode, factor, price, {key, kind}))
        if options:
            candidates.append((product, options))
    uncovered = universe.copy()
    selected = []
    while uncovered:
        if not candidates:
            raise ValueError(f'No priced representative for {len(uncovered)} catalog cases')
        best = max(candidates, key=lambda entry: len(set().union(*(o[3] for o in entry[1])) & uncovered))
        product, options = best
        if not (set().union(*(o[3] for o in options)) & uncovered):
            raise ValueError(f'No priced representative for {len(uncovered)} catalog cases')
        candidates.remove(best)
        lines = []
        while options:
            option = max(options, key=lambda o: len(o[3] & uncovered))
            barcode, factor, price, covered = option
            if not covered & uncovered:
                break
            options.remove(option)
            uncovered -= covered
            lines.append({
                'barcode': barcode['barcode'], 'barcode_type': barcode.get('barcode_type', 'CUSTOM'),
                'unit_id': barcode.get('unit_id'),
                'unit_name': (barcode.get('unit') or {}).get('name') or 'หน่วย',
                'factor': str(factor), 'qty': '1', 'catalog_price': str(price),
            })
        required = sum((Decimal(line['factor']) for line in lines), Decimal(0))
        if required > 10:
            for line in lines:
                qty = fractional_test_qty(Decimal(line['factor']), len(lines))
                if qty < Decimal('.0001'):
                    raise ValueError('Unit too large for a ten-base-unit smoke test')
                line['qty'] = str(qty)
            required = sum((Decimal(line['factor']) * Decimal(line['qty']) for line in lines), Decimal(0))
        if required > 10:
            raise ValueError('Unit coverage exceeds ten base units; review plan')
        selected.append({
            'product_id': product['id'], 'sku': product['sku_code'], 'name': product['name_th'],
            'server_id': product.get('server_id', product['id']),
            'receive_base_qty': '10', 'sold_base_qty': str(required), 'lines': lines,
        })
    return {
        'unit_cases': sum(key[0] == 'unit' for key in universe),
        'barcode_types': sorted(key[1] for key in universe if key[0] == 'barcode'),
        'products': selected,
    }
