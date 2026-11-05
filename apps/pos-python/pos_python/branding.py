"""Bundled JET POS branding, resolved beside the package in source and PyInstaller."""
from pathlib import Path

ASSETS = Path(__file__).resolve().parent / 'assets'


def app_icon():
    from PySide6.QtGui import QIcon
    return QIcon(str(ASSETS / 'icon.ico'))


def logo_label(height=64):
    from PySide6.QtCore import Qt
    from PySide6.QtGui import QPixmap
    from PySide6.QtWidgets import QLabel
    label = QLabel()
    label.setObjectName('mpmLogo')
    label.setPixmap(QPixmap(str(ASSETS / 'mpm-logo.jpg')).scaledToHeight(height, Qt.SmoothTransformation))
    label.setAlignment(Qt.AlignCenter)
    return label
