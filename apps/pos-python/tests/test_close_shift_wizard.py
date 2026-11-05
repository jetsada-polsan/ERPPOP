"""Touch workspace navigation and replacement regressions."""
from unittest.mock import patch
from PySide6.QtCore import QPoint
from PySide6.QtWidgets import QApplication, QPushButton
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
        assert d.size().width() == 1024 and d.size().height() == 768
        assert d.coins.verticalScrollBar().maximum() == 0
        for button in d.findChildren(QPushButton):
            if button.isVisible():
                top = button.mapTo(d, QPoint(0, 0))
                assert top.y() >= 0 and top.y() + button.height() <= 768
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
