import pytest
from PySide6.QtCore import Qt
from PySide6.QtWidgets import QMessageBox, QInputDialog, QTableWidgetItem
from ui.main_window import MainWindow

@pytest.fixture
def mock_dialogs(monkeypatch):
    monkeypatch.setattr(QMessageBox, "warning", lambda *args, **kwargs: QMessageBox.StandardButton.Ok)
    monkeypatch.setattr(QMessageBox, "information", lambda *args, **kwargs: QMessageBox.StandardButton.Ok)
    monkeypatch.setattr(QMessageBox, "critical", lambda *args, **kwargs: QMessageBox.StandardButton.Ok)
    monkeypatch.setattr(QMessageBox, "question", lambda *args, **kwargs: QMessageBox.StandardButton.Yes)
    monkeypatch.setattr(QInputDialog, "getText", lambda *args, **kwargs: ("Test Item", True))
    monkeypatch.setattr(QInputDialog, "getDouble", lambda *args, **kwargs: (100.0, True))

@pytest.fixture
def app_window(qtbot, tmp_path, mock_dialogs):
    db_path = str(tmp_path / "test_pos.db")
    from database.models import init_db, Product
    session = init_db(f"sqlite:///{db_path}")
    prod = Product(
        sku_code="12345",
        name_th="Test Item",
        default_price=100.0,
        barcode="12345",
        is_active=True
    )
    session.add(prod)
    session.commit()
    session.close()
    window = MainWindow(db_path=db_path)
    window.current_shift = {'id': 999, 'local_id': 1}
    qtbot.addWidget(window)
    return window

def test_add_item_to_cart(app_window, qtbot):
    app_window.search_input.setText("12345")
    qtbot.keyClick(app_window.search_input, Qt.Key_Return)
    assert app_window.cart_table.rowCount() == 1
    assert app_window.total_val.text() == "฿100.00"


def test_cart_preserves_eight_decimal_price_and_weight(app_window):
    from database.models import Product, init_db

    session = init_db(f"sqlite:///{app_window.db_path}")
    product = session.query(Product).filter_by(sku_code="12345").one()
    app_window.add_to_cart(
        product,
        qty="0.12345678",
        is_scale=True,
        price_override="29.12345678",
    )
    session.close()

    assert app_window.cart_table.item(0, 1).text() == "0.12345678"
    assert app_window.cart_table.item(0, 2).text() == "29.12345678"
    assert app_window.total_val.text() == "฿3.60"


def test_cart_line_displays_sum_to_bill_after_satang_rounding(app_window):
    app_window.cart_table.setRowCount(2)
    for row in range(2):
        app_window.cart_table.setItem(row, 0, QTableWidgetItem(f"Fractional {row}"))
        app_window.cart_table.setItem(row, 1, QTableWidgetItem("0.333"))
        app_window.cart_table.setItem(row, 2, QTableWidgetItem("0.05"))
        app_window.cart_table.setItem(row, 3, QTableWidgetItem("0.00"))

    app_window.update_cart_total()

    assert [app_window.cart_table.item(row, 3).text() for row in range(2)] == ["0.01", "0.02"]
    assert app_window.total_val.text() == "฿0.03"

def test_clear_cart_empty(app_window, qtbot):
    app_window.cancel_current_bill()
    assert app_window.cart_table.rowCount() == 0

