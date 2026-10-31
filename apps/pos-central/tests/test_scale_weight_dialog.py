from types import SimpleNamespace

from database.models import Product, init_db
from ui import scale_weight_dialog
from ui.scale_weight_dialog import ScaleWeightDialog


def _create_product(db_path):
    session = init_db(f"sqlite:///{db_path}")
    product = Product(
        sku_code="300006",
        name_th="สินค้าชั่งน้ำหนัก",
        default_price=100,
        pos_price=100,
        is_active=True,
        is_scale_item=True,
    )
    session.add(product)
    session.commit()
    product_id = product.id
    session.close()
    return product_id


def test_scale_label_scan_uses_erp_weight_and_locks_the_label(qtbot, tmp_path, monkeypatch):
    db_path = tmp_path / "pos.db"
    product_id = _create_product(db_path)
    response_data = {
        "mode": "scale",
        "product": {
            "id": product_id,
            "sku_code": "300006",
            "name_th": "สินค้าชั่งน้ำหนัก",
            "default_price": 100,
            "pos_price": 100,
        },
        "qty": 0.12345678,
        "barcode": "8010010007500",
        "barcode_type": "SCALE_WEIGHT",
    }
    monkeypatch.setattr(
        scale_weight_dialog.requests,
        "post",
        lambda *args, **kwargs: SimpleNamespace(status_code=200, json=lambda: response_data),
    )

    dialog = ScaleWeightDialog(
        db_path=str(db_path),
        api_config={"api_url": "https://erp.example", "pos_token": "test-token", "branch_id": 1},
    )
    qtbot.addWidget(dialog)
    assert dialog.selected_product is None

    dialog.scan_input.setText(response_data["barcode"])
    dialog.on_scan_barcode()

    assert dialog.selected_product.id == product_id
    assert dialog.weight_input.decimals() == 8
    assert dialog.weight_input.value() == 0.12345678
    assert not dialog.weight_input.isEnabled()
    assert dialog.source_barcode == response_data["barcode"]
    assert dialog.barcode_type == "SCALE_WEIGHT"


def test_plain_product_scan_selects_item_but_keeps_manual_weight(qtbot, tmp_path, monkeypatch):
    db_path = tmp_path / "pos.db"
    product_id = _create_product(db_path)
    response_data = {
        "mode": "barcode",
        "product": {
            "id": product_id,
            "sku_code": "300006",
            "name_th": "สินค้าชั่งน้ำหนัก",
            "default_price": 100,
            "pos_price": 100,
        },
        "qty": 1,
        "barcode": "300006",
        "barcode_type": "CUSTOM",
    }
    monkeypatch.setattr(
        scale_weight_dialog.requests,
        "post",
        lambda *args, **kwargs: SimpleNamespace(status_code=200, json=lambda: response_data),
    )

    dialog = ScaleWeightDialog(
        db_path=str(db_path),
        api_config={"api_url": "https://erp.example", "pos_token": "test-token", "branch_id": 1},
    )
    qtbot.addWidget(dialog)
    dialog.scan_input.setText(response_data["barcode"])
    dialog.on_scan_barcode()

    assert dialog.selected_product.id == product_id
    assert dialog.weight_input.value() == 1.0
    assert dialog.weight_input.isEnabled()
    assert dialog.source_barcode is None
    assert dialog.barcode_type is None
