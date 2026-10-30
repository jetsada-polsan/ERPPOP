"""Shared visual system for every PopCentral POS screen."""

from PySide6.QtGui import QFont
from PySide6.QtWidgets import QApplication, QWidget


POS_THEME_STYLESHEET = """
QDialog, QMainWindow {
    background-color: #f4f7fb;
    color: #1d2b3a;
    font-family: 'Segoe UI', 'Leelawadee UI', Tahoma, sans-serif;
}
QLabel, QCheckBox, QRadioButton, QGroupBox {
    color: #26384a;
    font-family: 'Segoe UI', 'Leelawadee UI', Tahoma, sans-serif;
}
QLineEdit, QComboBox, QSpinBox, QDoubleSpinBox, QDateEdit, QDateTimeEdit {
    min-height: 32px;
    padding: 6px 10px;
    color: #1d2b3a;
    background-color: #ffffff;
    border: 1px solid #cbd7e2;
    border-radius: 9px;
    selection-background-color: #147da8;
    selection-color: #ffffff;
}
QLineEdit:focus, QComboBox:focus, QSpinBox:focus, QDoubleSpinBox:focus,
QDateEdit:focus, QDateTimeEdit:focus {
    border: 2px solid #1688b5;
    background-color: #ffffff;
}
QComboBox::drop-down {
    width: 28px;
    border: none;
    border-left: 1px solid #e1e8ef;
    border-top-right-radius: 8px;
    border-bottom-right-radius: 8px;
}
QComboBox QAbstractItemView {
    color: #1d2b3a;
    background-color: #ffffff;
    border: 1px solid #cbd7e2;
    selection-background-color: #e6f2f8;
    selection-color: #0b5e83;
    outline: none;
    padding: 4px;
}
QPushButton {
    min-height: 34px;
    padding: 6px 12px;
    color: #29465b;
    background-color: #f1f5f8;
    border: 1px solid #d3dfe7;
    border-radius: 9px;
    font-family: 'Segoe UI', 'Leelawadee UI', Tahoma, sans-serif;
    font-weight: 600;
}
QPushButton:hover {
    background-color: #e7f0f5;
    border-color: #b8ccd9;
}
QPushButton:pressed { background-color: #dceaf2; }
QPushButton:focus { border-color: #1688b5; }
QPushButton:disabled {
    color: #94a3b2;
    background-color: #e9eef3;
    border-color: #e1e7ed;
}
QTableWidget, QTreeWidget, QListWidget {
    color: #26384a;
    background-color: #ffffff;
    border: 1px solid #d8e2eb;
    border-radius: 10px;
    gridline-color: #edf1f5;
    selection-background-color: #e5f2f8;
    selection-color: #0b5e83;
    alternate-background-color: #f8fafc;
}
QHeaderView::section {
    color: #29465b;
    background-color: #edf4f8;
    border: none;
    border-bottom: 1px solid #d7e3eb;
    padding: 9px 10px;
    font-weight: 700;
}
QScrollBar:vertical {
    width: 10px;
    margin: 3px 2px 3px 2px;
    background: transparent;
}
QScrollBar::handle:vertical {
    min-height: 28px;
    background: #c9d6e1;
    border-radius: 5px;
}
QScrollBar::handle:vertical:hover { background: #9eb3c3; }
QScrollBar::add-line:vertical, QScrollBar::sub-line:vertical { height: 0; }
QScrollBar::add-page:vertical, QScrollBar::sub-page:vertical { background: transparent; }
QToolTip {
    color: #ffffff;
    background-color: #19374d;
    border: 1px solid #19374d;
    border-radius: 5px;
    padding: 5px 8px;
}
QMessageBox, QInputDialog {
    background-color: #ffffff;
}
"""


def apply_pos_theme(widget: QWidget) -> None:
    """Apply shared POS styling while preserving explicit semantic widget colors."""
    app = QApplication.instance()
    if app is not None and not app.property("poscentral_theme_installed"):
        app.setFont(QFont("Segoe UI", 10))
        app.setStyleSheet(f"{app.styleSheet()}\n{POS_THEME_STYLESHEET}")
        app.setProperty("poscentral_theme_installed", True)

    widget.setProperty("poscentral_theme", True)
    widget.setFont(QFont("Segoe UI", 10))
    current = widget.styleSheet()
    if "QDialog, QMainWindow" not in current:
        widget.setStyleSheet(f"{current}\n{POS_THEME_STYLESHEET}")
