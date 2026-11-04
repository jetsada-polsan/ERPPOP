from __future__ import annotations

from dataclasses import dataclass, field
from decimal import Decimal
from typing import Literal


Money = Decimal
PromoType = Literal[
    "free_item",
    "discount",
    "bundle_price",
    "mix_bundle",
    "mix_free",
    "bill_discount",
    "bill_free_item",
    "bill_addon",
]


@dataclass(frozen=True)
class Product:
    id: int
    sku_code: str
    name_th: str
    pos_price: Money
    stock_qty: Decimal = Decimal("0")
    unit_name: str = "ชิ้น"


@dataclass(frozen=True)
class CartLine:
    product: Product
    qty: Decimal
    barcode: str | None = None
    barcode_type: str | None = None


@dataclass(frozen=True)
class Promotion:
    id: int
    name: str
    promo_type: PromoType
    product_id: int | None = None
    product_ids: tuple[int, ...] = ()
    min_qty: Decimal = Decimal("1")
    min_amount: Money | None = None
    discount_type: Literal["percent", "baht"] | None = None
    discount_value: Money | None = None
    max_discount: Money | None = None
    bundle_price: Money | None = None
    free_product: Product | None = None
    free_qty: Decimal | None = None


@dataclass(frozen=True)
class PricedItem:
    product_id: int
    qty: Decimal
    unit_price: Money
    barcode: str | None = None
    barcode_type: str | None = None


@dataclass(frozen=True)
class FreeItem:
    product_id: int
    sku_code: str
    name_th: str
    qty: Decimal
    promotion: str


@dataclass(frozen=True)
class CartPricing:
    subtotal: Money
    promo_discount: Money
    manual_discount: Money
    total: Money
    vat: Money
    before_vat: Money
    items: tuple[PricedItem, ...] = field(default_factory=tuple)
    free_items: tuple[FreeItem, ...] = field(default_factory=tuple)
