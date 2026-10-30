from decimal import Decimal, InvalidOperation
from typing import Any, Dict, Optional

from database.models import Product, ProductBarcode, ScaleProfile


class ScaleBarcodeParser:
    """Decode scale labels using ERP profiles; weight values are grams."""

    @staticmethod
    def parse(barcode: str, session) -> Optional[Dict[str, Any]]:
        scanned = str(barcode or "").strip()
        if not scanned.isdigit():
            return None

        profiles = (
            session.query(ScaleProfile)
            .filter(ScaleProfile.is_active.is_(True), ScaleProfile.code.isnot(None))
            .order_by(ScaleProfile.check_digit.desc(), ScaleProfile.total_length.desc())
            .all()
        )
        for profile in profiles:
            if len(scanned) != profile.total_length or not scanned.startswith(str(profile.prefix)):
                continue
            if profile.value_type not in ("price", "weight"):
                continue

            plu_length = int(profile.plu_length or 0)
            value_length = int(profile.value_length or 0)
            if plu_length <= 0 or value_length <= 0 or plu_length + value_length > len(scanned):
                continue

            plu = scanned[:plu_length]
            raw_value = scanned[plu_length:plu_length + value_length]
            if not plu.isdigit() or not raw_value.isdigit():
                continue
            if profile.check_digit == "ean13" and not ScaleBarcodeParser._valid_ean13(scanned):
                continue

            try:
                product = session.query(Product).filter(
                    (Product.sku_code == plu) | (Product.barcode == plu)
                ).first()
                if product is None:
                    cached = session.query(ProductBarcode).filter_by(barcode=plu).first()
                    if cached:
                        product = session.query(Product).filter_by(id=cached.product_id).first()
                if not product:
                    return None
                unit_price = Decimal(str(product.pos_price or product.default_price or 0))
                if unit_price <= 0:
                    return None
                if profile.value_type == "price":
                    total_price = Decimal(raw_value) / Decimal("100")
                    quantity = total_price / unit_price
                else:
                    divisor = Decimal(str(profile.weight_divisor or 1000))
                    if divisor <= 0:
                        return None
                    quantity = Decimal(raw_value) / divisor
                    total_price = quantity * unit_price
            except (InvalidOperation, ArithmeticError, TypeError, ValueError):
                return None

            return {
                "is_scale": True,
                "profile_name": profile.name,
                "prefix": profile.prefix,
                "item_code": plu,
                "weight": float(quantity),
                "price": float(total_price),
                "value_type": profile.value_type,
                "raw_barcode": scanned,
                "barcode_type": "SCALE_WEIGHT",
            }

        return None

    @staticmethod
    def _valid_ean13(barcode: str) -> bool:
        if len(barcode) != 13 or not barcode.isdigit():
            return False
        weighted = sum(
            int(digit) * (1 if index % 2 == 0 else 3)
            for index, digit in enumerate(barcode[:12])
        )
        return (10 - weighted % 10) % 10 == int(barcode[-1])
