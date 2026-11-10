"""Unit and geometry tests for cash count denomination + / - buttons and clear all action."""
from decimal import Decimal
from unittest.mock import patch, Mock
import pytest
from PySide6.QtCore import QPoint, Qt
from PySide6.QtWidgets import QPushButton

from pos_python.shift_float import FloatClose
from pos_python.touch_dialogs import TouchMessage
from test_shift_float import POLICY
from test_touch_popups import scene


@pytest.fixture
def float_dialog(scene):
    app, db, service, w, api = scene
    protocol = FloatClose(api, POLICY, 91)
    d = w.dialog_types['float_closing'](w, protocol, POLICY)
    d.resize(900, 680)
    d.show()
    app.processEvents()
    yield app, d
    d.reject()
    app.processEvents()


def test_plus_button_increments_count_and_updates_amount(float_dialog):
    app, d = float_dialog
    # Row 0 is 1000 baht
    assert d.coin_fields[0].text() == '0'
    assert Decimal(d.amount.text() or '0') == Decimal('0')

    d.coin_plus_buttons[0].click()
    app.processEvents()
    assert d.coin_fields[0].text() == '1'
    assert d.coins.item(0, 2).text() == '1,000.00'
    assert Decimal(d.amount.text()) == Decimal('1000.00')

    # Click + again -> 2
    d.coin_plus_buttons[0].click()
    app.processEvents()
    assert d.coin_fields[0].text() == '2'
    assert d.coins.item(0, 2).text() == '2,000.00'
    assert Decimal(d.amount.text()) == Decimal('2000.00')

    # Row 2 is 100 baht -> click + -> 2000 + 100 = 2100
    d.coin_plus_buttons[2].click()
    app.processEvents()
    assert d.coin_fields[2].text() == '1'
    assert d.coins.item(2, 2).text() == '100.00'
    assert Decimal(d.amount.text()) == Decimal('2100.00')


def test_minus_button_at_zero_remains_zero(float_dialog):
    app, d = float_dialog
    # At 0, clicking minus should stay 0 (never negative)
    for i in range(11):
        assert d.coin_fields[i].text() == '0'
        d.coin_minus_buttons[i].click()
        app.processEvents()
        assert d.coin_fields[i].text() == '0'
    assert Decimal(d.amount.text() or '0') == Decimal('0')


def test_minus_button_decrements_count_and_updates_amount(float_dialog):
    app, d = float_dialog
    # Set row 1 (500) to 3
    d.coin_fields[1].setText('3')
    app.processEvents()
    assert Decimal(d.amount.text()) == Decimal('1500.00')

    # Click minus once -> 2
    d.coin_minus_buttons[1].click()
    app.processEvents()
    assert d.coin_fields[1].text() == '2'
    assert Decimal(d.amount.text()) == Decimal('1000.00')

    # Click minus twice -> 0
    d.coin_minus_buttons[1].click()
    d.coin_minus_buttons[1].click()
    app.processEvents()
    assert d.coin_fields[1].text() == '0'
    assert Decimal(d.amount.text()) == Decimal('0.00')

    # Click minus again at 0 -> still 0
    d.coin_minus_buttons[1].click()
    app.processEvents()
    assert d.coin_fields[1].text() == '0'
    assert Decimal(d.amount.text()) == Decimal('0.00')


def test_empty_or_non_integer_treated_as_zero_on_plus_or_minus(float_dialog):
    app, d = float_dialog
    # Empty string + 1 -> 1
    d.coin_fields[0].setText('')
    d.coin_plus_buttons[0].click()
    app.processEvents()
    assert d.coin_fields[0].text() == '1'
    assert Decimal(d.amount.text()) == Decimal('1000.00')

    # Non-integer (e.g. 1.5) + 1 -> 0 + 1 = 1
    d.coin_fields[1].setText('1.5')
    d.coin_plus_buttons[1].click()
    app.processEvents()
    assert d.coin_fields[1].text() == '1'

    # Junk string + 1 -> 0 + 1 = 1
    d.coin_fields[2].setText('xyz')
    d.coin_plus_buttons[2].click()
    app.processEvents()
    assert d.coin_fields[2].text() == '1'

    # Non-integer - 1 -> max(0, 0 - 1) = 0
    d.coin_fields[3].setText('2.7')
    d.coin_minus_buttons[3].click()
    app.processEvents()
    assert d.coin_fields[3].text() == '0'


def test_clear_all_when_all_zero_does_not_prompt(float_dialog):
    app, d = float_dialog
    with patch.object(TouchMessage, 'question') as mock_question:
        d.clear_btn.click()
        app.processEvents()
        mock_question.assert_not_called()
    for entry in d.coin_fields:
        assert entry.text() == '0'


def test_clear_all_with_values_confirmed(float_dialog):
    app, d = float_dialog
    d.coin_plus_buttons[0].click()  # 1000 x 1
    d.coin_plus_buttons[4].click()  # 20 x 1
    d.coin_plus_buttons[10].click() # 0.25 x 1
    app.processEvents()
    assert Decimal(d.amount.text()) == Decimal('1020.25')

    with patch.object(TouchMessage, 'question', return_value=TouchMessage.Yes) as mock_question:
        d.clear_btn.click()
        app.processEvents()
        mock_question.assert_called_once()
        assert "ล้างข้อมูล" in mock_question.call_args[0][1]

    for entry in d.coin_fields:
        assert entry.text() == '0'
    assert Decimal(d.amount.text()) == Decimal('0.00')


def test_clear_all_with_values_cancelled(float_dialog):
    app, d = float_dialog
    d.coin_plus_buttons[0].click()  # 1000 x 1
    d.coin_plus_buttons[4].click()  # 20 x 1
    app.processEvents()
    assert Decimal(d.amount.text()) == Decimal('1020.00')

    with patch.object(TouchMessage, 'question', return_value=TouchMessage.No) as mock_question:
        d.clear_btn.click()
        app.processEvents()
        mock_question.assert_called_once()

    # Values must remain unchanged
    assert d.coin_fields[0].text() == '1'
    assert d.coin_fields[4].text() == '1'
    assert Decimal(d.amount.text()) == Decimal('1020.00')


def test_exact_cents_calculation_with_fractional_coins(float_dialog):
    app, d = float_dialog
    # Click 1 for each of 11 denominations:
    # 1000 + 500 + 100 + 50 + 20 + 10 + 5 + 2 + 1 + 0.50 + 0.25 = 1688.75
    for i in range(11):
        d.coin_plus_buttons[i].click()
    app.processEvents()

    assert d.coin_fields[9].text() == '1'   # 0.50
    assert d.coin_fields[10].text() == '1'  # 0.25
    assert d.coins.item(9, 2).text() == '0.50'
    assert d.coins.item(10, 2).text() == '0.25'
    assert Decimal(d.amount.text()) == Decimal('1688.75')

    # Add one more 0.25 coin -> 1689.00
    d.coin_plus_buttons[10].click()
    app.processEvents()
    assert d.coin_fields[10].text() == '2'
    assert d.coins.item(10, 2).text() == '0.50'
    assert Decimal(d.amount.text()) == Decimal('1689.00')

    # Add one more 0.50 coin -> 1689.50
    d.coin_plus_buttons[9].click()
    app.processEvents()
    assert d.coin_fields[9].text() == '2'
    assert d.coins.item(9, 2).text() == '1.00'
    assert Decimal(d.amount.text()) == Decimal('1689.50')


def test_auto_repeat_enabled_on_buttons(float_dialog):
    _, d = float_dialog
    for i in range(11):
        m = d.coin_minus_buttons[i]
        p = d.coin_plus_buttons[i]
        assert m.autoRepeat() is True
        assert p.autoRepeat() is True
        assert m.autoRepeatDelay() >= 200
        assert p.autoRepeatDelay() >= 200
        assert m.autoRepeatInterval() <= 200
        assert p.autoRepeatInterval() <= 200


def test_button_touch_geometry_at_least_44px(float_dialog):
    _, d = float_dialog
    for i in range(11):
        m = d.coin_minus_buttons[i]
        p = d.coin_plus_buttons[i]
        assert m.minimumWidth() >= 44
        assert m.minimumHeight() >= 44
        assert p.minimumWidth() >= 44
        assert p.minimumHeight() >= 44
        assert m.height() >= 44
        assert p.height() >= 44
    assert d.clear_btn.height() >= 30


def test_table_fits_screen_all_11_rows_visible(float_dialog):
    app, d = float_dialog
    # Max dimensions per specification
    assert d.width() <= 1024
    assert d.height() <= 680

    # All 11 rows exist
    assert d.coins.rowCount() == 11

    # Viewport has enough height to display all 11 rows without scrolling
    total_rows_height = sum(d.coins.rowHeight(r) for r in range(11))
    assert d.coins.viewport().height() >= total_rows_height

    # All visible buttons stay strictly within dialog boundaries
    for button in d.findChildren(QPushButton):
        if button.isVisible():
            top = button.mapTo(d, QPoint(0, 0))
            assert top.y() >= 0
            assert top.y() + button.height() <= d.height()
