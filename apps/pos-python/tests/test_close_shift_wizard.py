"""Touch workspace navigation and replacement regressions."""
from unittest.mock import patch
from PySide6.QtCore import QPoint
from PySide6.QtWidgets import QApplication, QDialog, QPushButton
from pos_python.touch_dialogs import TouchDialog, ValueDialog
from pos_python.shift_float import FloatClose
from test_shift_float import POLICY, report
from test_touch_popups import scene


def test_touch_dialog_replaces_and_restores_previous(scene):
    app, _, _, window, _ = scene
    first = TouchDialog(window)
    second = ValueDialog(first)
    third = TouchDialog(second)
    first.show(); app.processEvents()
    second.show(); app.processEvents()
    assert not first.isVisible() and second.isVisible()
    third.show(); app.processEvents()
    assert not first.isVisible() and not second.isVisible() and third.isVisible()
    assert len([w for w in QApplication.topLevelWidgets() if isinstance(w, TouchDialog) and w.isVisible()]) == 1
    third.reject(); app.processEvents()
    assert second.isVisible() and not first.isVisible()
    second.reject(); app.processEvents()
    assert first.isVisible()
    first.reject()


def test_touch_dialog_never_leaves_a_visible_backdrop_after_close(scene):
    app, _, _, window, _ = scene
    dialog = TouchDialog(window)
    dialog.show()
    app.processEvents()
    assert dialog._backdrop.isVisible()
    dialog.done(QDialog.DialogCode.Accepted)
    app.processEvents()
    assert not dialog.isVisible()
    assert not dialog._backdrop.isVisible()


def test_wizard_steps_geometry_blind_and_repreview(scene):
    app, _, _, w, api = scene
    api.post.return_value = dict(preview=True, shift=report())
    d = w.dialog_types['float_closing'](w, FloatClose(api, POLICY, 91), POLICY)
    d.show(); app.processEvents()
    assert d.steps.currentIndex() == 0
    d.amount.setText('15432.75'); d.reference.setText('BAG-001')
    for index in range(4):
        assert d.steps.currentIndex() == index
        app.processEvents()
        assert d.width() <= 900 and d.height() <= 680
        assert all(d.coins.rowHeight(row) >= entry.minimumSizeHint().height()
                   for row, entry in enumerate(d.coin_fields))
        for button in d.findChildren(QPushButton):
            if button.isVisible():
                top = button.mapTo(d, QPoint(0, 0))
                assert top.y() >= 0 and top.y() + button.height() <= d.height()
        if index < 3: d.advance()
    assert api.post.call_count == 2
    d.previous(); assert d.steps.currentIndex() == 2
    d.reference.setText('OTHER')
    assert d.protocol.preview is None and not d.submit.isEnabled()
    d.advance()  # stale server reference cannot authorize closing
    assert d.steps.currentIndex() == 2
    d.reject()


def test_coin_keypad_returns_to_total(scene):
    app, _, _, w, api = scene
    d = w.dialog_types['float_closing'](w, FloatClose(api, POLICY, 91), POLICY)
    d.show(); app.processEvents()
    d.coin_fields[0].setFocus(); app.processEvents()
    assert d.pad.target is d.coin_fields[0]
    d.amount.setFocus(); app.processEvents()
    assert d.pad.target is d.amount
    d.reject()


def test_close_workspace_fits_screen_and_drop_navigation(scene):
    app, _, _, w, api = scene
    d = w.dialog_types['float_closing'](w, FloatClose(api, POLICY, 91), POLICY)
    d.show(); app.processEvents()
    available = d.screen().availableGeometry()
    assert available.contains(d.frameGeometry())
    d.navigate(1); app.processEvents()
    for size in (0, 1, 4, 5):
        d.drop_rows = [dict(amount='100', reference_no='BAG', reason='นำส่ง')] * size
        d.drop_page = 0
        d.show_drops(0); app.processEvents()
        assert all(button.isVisible() == (size > 4) for button in d.drop_buttons)
    assert not d.drop_buttons[0].isEnabled()
    assert d.drop_buttons[1].isEnabled()
    d.drop_buttons[1].click()
    assert d.drop_page == 1
    assert d.drop_buttons[0].isEnabled()
    assert not d.drop_buttons[1].isEnabled()
    for index in range(4):
        d.navigate(index); app.processEvents()
        for button in (d.back, d.submit if index == 3 else d.next):
            assert d.rect().contains(button.mapTo(d, QPoint(0, 0)))
            assert d.rect().contains(button.mapTo(d, button.rect().bottomRight()))
    d.reject()
