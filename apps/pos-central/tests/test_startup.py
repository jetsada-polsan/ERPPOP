import pytest
import os

from PySide6.QtWidgets import QApplication
from ui.main_window import MainWindow

def test_mainwindow_initialization(qtbot, tmp_path, monkeypatch):
    # This test verifies that the MainWindow can be initialized without fatal errors (like AttributeError)
    monkeypatch.setenv("LOCALAPPDATA", str(tmp_path))
    window = MainWindow()
    qtbot.addWidget(window)
    assert window is not None
    assert window.db_path == os.path.join(str(tmp_path), "PopCentralPOS", "data", "pos.db")
    assert hasattr(window, "manual_open_drawer")
    assert hasattr(window, "cancel_current_bill")
    assert hasattr(window, "create_products_panel")


def test_unpaired_startup_does_not_create_a_background_sync_worker(qtbot, tmp_path, monkeypatch):
    monkeypatch.setenv("LOCALAPPDATA", str(tmp_path))
    window = MainWindow()
    qtbot.addWidget(window)

    assert window.config.get("pos_token", "") == ""
    assert window.start_sync() is False
    assert not hasattr(window, "sync_worker")
