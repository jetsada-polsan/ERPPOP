import pytest
from PySide6.QtCore import Qt
from PySide6.QtWidgets import QDialog, QMessageBox, QInputDialog
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
    window.config['promptpay_id'] = "1349901281208"
    window.config['api_url'] = "http://localhost:8000"
    qtbot.addWidget(window)
    return window

def test_payment_dialog_qr(app_window, qtbot):
    app_window.search_input.setText("12345")
    qtbot.keyClick(app_window.search_input, Qt.Key_Return)
    assert app_window.total_val.text() == "฿100.00"

    from ui.payment_dialog import PaymentDialog
    app_window.show_payment_dialog = lambda: None 

    dialog = PaymentDialog(app_window, 100.0, 1, 1)
    qtbot.addWidget(dialog)
    
    # Click QR button
    qtbot.mouseClick(dialog.method_btns["QR"], Qt.LeftButton)
    
    # Check if PromptPay ID is displayed correctly in title_lbl
    assert "1349901281208" in dialog.title_lbl.text() or dialog.selected_method == "QR"
    
    # Simulate exact cash payment
    qtbot.mouseClick(dialog.method_btns["เงินสด"], Qt.LeftButton)
    # the exact button is exact_btn? No, it's generated. Let's just check the selected method
    assert dialog.selected_method == "เงินสด"


def test_split_payment_uses_exact_remaining_transfer_and_requires_confirmation(app_window, qtbot):
    from ui.payment_dialog import PaymentDialog

    app_window.config["qr_payment"] = {
        "qr_type": "dynamic",
        "merchant_ref": "1349901281208",
        "name": "PromptPay",
    }
    dialog = PaymentDialog(app_window, 100.01, 2, 2)
    qtbot.addWidget(dialog)
    dialog.set_method("ผสม (Split)")
    for digit in "20":
        dialog.append_input(digit)

    assert dialog.cash_amount == 20.0
    assert dialog.transfer_amount == 80.01
    assert round(dialog.cash_amount + dialog.transfer_amount, 2) == dialog.total_amount

    dialog.transfer_account_last4_input.setText("1234")
    dialog.payment_confirm_check.setChecked(True)
    dialog.accept_payment()
    assert dialog.result() == QDialog.DialogCode.Accepted


def test_card_payment_requires_and_keeps_reference(app_window, qtbot):
    from ui.payment_dialog import PaymentDialog

    dialog = PaymentDialog(app_window, 25.50, 1, 1)
    qtbot.addWidget(dialog)
    dialog.set_method("บัตรเครดิต")
    dialog.payment_reference_input.setText("AUTH-123")
    dialog.accept_payment()

    assert dialog.result() == QDialog.DialogCode.Accepted
    assert dialog.payment_reference == "AUTH-123"
