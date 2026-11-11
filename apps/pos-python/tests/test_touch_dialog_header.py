"""ทุกหน้าต่างของ POS ต้องมีหัวและปุ่ม × ปิดได้ (บั๊ก 0.9.12: exec() ถูกแทรกกลาง __init__ หัวหายหมด)"""
from PySide6.QtWidgets import QApplication, QDialog, QFrame, QPushButton

from pos_python.touch_dialogs import TouchDialog


def _app():
    return QApplication.instance() or QApplication([])


def test_touch_dialog_has_header_with_a_working_close_button():
    _app()
    dialog = TouchDialog(None, "ใบปิดกะ", "สรุปกะ")
    header = dialog.findChild(QFrame, "touchHeader")
    close = dialog.findChild(QPushButton, "btnModalClose")
    assert header is not None and close is not None
    assert dialog.body.indexOf(header) == 0, "หัวต้องอยู่บนสุดของหน้าต่าง"
    dialog.show()
    close.click()
    assert dialog.result() == QDialog.DialogCode.Rejected and not dialog.isVisible()


def test_touch_dialog_hides_its_backdrop_when_finished():
    from unittest.mock import patch

    _app()
    with patch.object(TouchDialog, "_hide_backdrop") as hide:
        dialog = TouchDialog(None, "ทดสอบ", "ฉากหลัง")
        dialog.show()
        dialog.reject()
    assert hide.called
