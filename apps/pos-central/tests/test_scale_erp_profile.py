from decimal import Decimal

from database.models import Product, ScaleProfile, init_db
from services.scale_service import ScaleBarcodeParser
from sync_worker import SyncWorker


def _ean13(body):
    weighted = sum(int(digit) * (1 if index % 2 == 0 else 3) for index, digit in enumerate(body))
    return body + str((10 - weighted % 10) % 10)


def test_price_label_uses_erp_profile_and_preserves_raw_barcode(tmp_path):
    session = init_db(f"sqlite:///{tmp_path / 'scale.db'}")
    session.add(Product(
        id=801001,
        sku_code="801001",
        name_th="สินค้าแบ่งชั่ง",
        default_price=100,
        pos_price=100,
    ))
    session.add(ScaleProfile(
        name="B001 price label",
        code="B001_PRICE",
        prefix="80",
        plu_length=6,
        value_length=6,
        value_type="price",
        check_digit="ean13",
        total_length=13,
        is_active=True,
    ))
    session.commit()

    barcode = _ean13("801001000500")
    result = ScaleBarcodeParser.parse(barcode, session)

    assert result["item_code"] == "801001"
    assert result["raw_barcode"] == barcode
    assert result["barcode_type"] == "SCALE_WEIGHT"
    assert Decimal(str(result["price"])) == Decimal("5.0")
    assert Decimal(str(result["weight"])) == Decimal("0.05")
    session.close()


def test_invalid_ean_check_digit_does_not_create_scale_line(tmp_path):
    session = init_db(f"sqlite:///{tmp_path / 'scale-invalid.db'}")
    session.add(Product(id=801001, sku_code="801001", name_th="สินค้า", default_price=100, pos_price=100))
    session.add(ScaleProfile(
        name="B001 price label", code="B001_PRICE", prefix="80", plu_length=6,
        value_length=6, value_type="price", check_digit="ean13", total_length=13, is_active=True,
    ))
    session.commit()

    valid = _ean13("801001000500")
    invalid = valid[:-1] + str((int(valid[-1]) + 1) % 10)
    assert ScaleBarcodeParser.parse(invalid, session) is None
    session.close()


def test_weight_label_uses_grams_from_erp_as_kilograms(tmp_path):
    session = init_db(f"sqlite:///{tmp_path / 'scale-weight.db'}")
    session.add(Product(id=801001, sku_code="801001", name_th="สินค้า", default_price=100, pos_price=100))
    session.add(ScaleProfile(
        name="weight label", code="B001_WEIGHT", prefix="80", plu_length=6,
        value_length=6, value_type="weight", check_digit="ean13", total_length=13, is_active=True,
    ))
    session.commit()

    result = ScaleBarcodeParser.parse(_ean13("801001001250"), session)
    assert result["value_type"] == "weight"
    assert Decimal(str(result["weight"])) == Decimal("1.25")
    assert Decimal(str(result["price"])) == Decimal("125.0")
    session.close()


def test_sync_worker_replaces_erp_profiles_without_local_prefix_defaults(tmp_path):
    db_path = str(tmp_path / "profiles.db")
    session = init_db(f"sqlite:///{db_path}")
    session.add(ScaleProfile(name="stale local guess", prefix="800", is_active=True))
    session.commit()
    session.close()

    worker = SyncWorker(db_path, "https://erp.example", "device-token")
    profiles = [{
        "code": "B001_PRICE",
        "prefix": "80",
        "plu_length": 6,
        "value_length": 6,
        "value_type": "price",
        "check_digit": "ean13",
        "total_length": 13,
    }]
    assert worker._sync_scale_profiles(profiles)

    session = init_db(f"sqlite:///{db_path}")
    rows = session.query(ScaleProfile).filter(ScaleProfile.code.isnot(None)).all()
    assert len(rows) == 1
    assert rows[0].code == "B001_PRICE"
    assert rows[0].prefix == "80"
    assert not worker._sync_scale_profiles(profiles)
    session.close()
