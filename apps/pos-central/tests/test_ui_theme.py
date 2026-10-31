from PySide6.QtWidgets import QApplication, QDialog

from ui.theme import POS_THEME_STYLESHEET, apply_pos_theme


def test_shared_theme_is_installed_for_all_pos_windows(qtbot):
    dialog = QDialog()
    qtbot.addWidget(dialog)

    apply_pos_theme(dialog)

    app = QApplication.instance()
    assert dialog.property("poscentral_theme") is True
    assert app.property("poscentral_theme_installed") is True
    assert "QLineEdit:focus" in app.styleSheet()
    assert "QHeaderView::section" in dialog.styleSheet()
    assert "#147da8" in POS_THEME_STYLESHEET
