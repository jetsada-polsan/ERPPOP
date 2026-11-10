"""Tests for POS backup before shift close and offline PIN expiry warning."""
import os
os.environ.setdefault("QT_QPA_PLATFORM", "offscreen")

import sqlite3
from datetime import datetime, timedelta, timezone
from decimal import Decimal
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch
import pytest

from PySide6.QtCore import QEvent
from PySide6.QtWidgets import QApplication, QDialog, QPushButton

from pos_python.database import connect
from pos_python.services import PosService, now
from pos_python.provisioning import ProvisioningService
from pos_python.settings_service import SettingsService
from pos_python.touch_dialogs import TouchMessage, BackupCopyPromptDialog
from pos_python.backup import (
    backup_database,
    prune_backups,
    maybe_daily_auto_backup,
    check_offline_pin_expiry,
)
from pos_python.ui import run_ui


@pytest.fixture
def test_db(tmp_path):
    """Create a temporary populated database for testing."""
    db_path = tmp_path / "source.db"
    db = connect(db_path)
    timestamp = now()
    db.execute(
        """INSERT INTO local_cashiers (id, code, name, pin_hash, active, synced_at, offline_valid_until)
        VALUES (1, 'C001', 'สมชาย ทดสอบ', 'hash', 1, ?, ?)""",
        (timestamp, (datetime.now(timezone.utc) + timedelta(days=2)).isoformat()),
    )
    db.execute(
        """INSERT INTO products (id, sku, name, unit_name, price, updated_at)
        VALUES (1, 'SKU-001', 'หมูสับ', 'กก.', '120.00', ?)""",
        (timestamp,),
    )
    db.execute(
        """INSERT INTO device_settings (key, value, updated_at)
        VALUES ('terminal_code', '"TERM-01"', ?)""",
        (timestamp,),
    )
    db.commit()
    yield db, db_path
    db.close()


@pytest.fixture
def ui_scene(tmp_path):
    """Create a headless Qt UI scene with PosWindow for backup/shift testing."""
    app = QApplication.instance() or QApplication([])
    db_path = tmp_path / "ui_test.db"
    db = connect(db_path)
    timestamp = now()
    db.execute(
        """INSERT INTO local_cashiers (id, code, name, pin_hash, active, synced_at, server_id, offline_valid_until)
        VALUES (1, 'UI001', 'แคชเชียร์ 1', 'hash', 1, ?, 10, ?)""",
        (timestamp, (datetime.now(timezone.utc) + timedelta(days=5)).isoformat()),
    )
    db.execute(
        """INSERT INTO products (id, sku, name, unit_name, price, updated_at)
        VALUES (1, 'SKU-1', 'สินค้า 1', 'ชิ้น', '50.00', ?)""",
        (timestamp,),
    )
    db.execute(
        """INSERT INTO device_settings (key, value, updated_at)
        VALUES ('terminal_code', '"TERM-UI-01"', ?)""",
        (timestamp,),
    )
    db.commit()

    service = PosService(db)
    shift_id = service.open_shift(1, "TERM-UI-01", 1, Decimal("200.00"))

    api = Mock()
    api.get.return_value = {"shift": {"id": 10, "expected_cash": "200.00"}}
    api.post.return_value = {"success": True}

    online = SimpleNamespace(
        branch_id=1,
        terminal_id="TERM-UI-01",
        profile={"branch_id": 1, "device": {"terminal_code": "TERM-UI-01"}},
        online=True,
        error="",
        provisioning=ProvisioningService(db, api),
        worker=SimpleNamespace(online=True, pending=0, last_error="", stop=lambda: None, wake=lambda: None),
    )

    data_dir = tmp_path / "pos_data"
    data_dir.mkdir(parents=True, exist_ok=True)

    window = run_ui(service, online=online, data_dir=data_dir, app=app)
    window.shift_id = shift_id
    window.cashier = db.execute("SELECT * FROM local_cashiers WHERE id = 1").fetchone()
    window.showNormal()
    window.resize(1024, 768)
    window.pages.setCurrentIndex(0)
    app.processEvents()

    yield app, db, service, window, data_dir
    window._allow_close = True
    window.close()
    window.deleteLater()
    app.sendPostedEvents(None, QEvent.DeferredDelete)
    db.close()


def test_backup_database_integrity_and_data_match(test_db, tmp_path):
    """Backup produces an integrity-checked SQLite file matching original data."""
    db, db_path = test_db
    dest_dir = tmp_path / "backups"

    # 1. Backup from open connection
    backup_file = backup_database(db, dest_dir, terminal_id="TERM-01")
    assert backup_file.is_file()
    assert backup_file.name.startswith("pos-backup-TERM-01-")
    assert backup_file.name.endswith(".sqlite")

    # Verify integrity of backup
    chk_conn = connect(backup_file)
    try:
        integrity = chk_conn.execute("PRAGMA integrity_check").fetchall()
        assert integrity[0][0] == "ok"

        # Verify data matches original
        cashier = chk_conn.execute("SELECT code, name FROM local_cashiers WHERE id = 1").fetchone()
        assert cashier["code"] == "C001"
        assert cashier["name"] == "สมชาย ทดสอบ"

        product = chk_conn.execute("SELECT sku, name, price FROM products WHERE id = 1").fetchone()
        assert product["sku"] == "SKU-001"
        assert product["name"] == "หมูสับ"
        assert product["price"] == "120.00"
    finally:
        chk_conn.close()

    # 2. Backup from file path
    dest_dir_2 = tmp_path / "backups_from_path"
    backup_file_2 = backup_database(db_path, dest_dir_2)
    assert backup_file_2.is_file()
    assert backup_file_2.name.startswith("pos-backup-")
    chk_conn_2 = connect(backup_file_2)
    try:
        assert chk_conn_2.execute("PRAGMA integrity_check").fetchall()[0][0] == "ok"
    finally:
        chk_conn_2.close()


def test_backup_integrity_failure_cleans_up_and_raises(test_db, tmp_path):
    """When integrity check fails, raises RuntimeError and removes corrupted file."""
    db, _ = test_db
    dest_dir = tmp_path / "backups_fail"
    dest_dir.mkdir(parents=True, exist_ok=True)

    orig_connect = sqlite3.connect
    call_count = [0]

    class MockChkConn:
        def execute(self, sql):
            class MockCursor:
                def fetchall(self):
                    return [("corrupt: page 2 is wrong",)]
            return MockCursor()

        def close(self):
            pass

    def fake_connect(path, *args, **kwargs):
        call_count[0] += 1
        if call_count[0] == 2:
            return MockChkConn()
        return orig_connect(path, *args, **kwargs)

    with patch("pos_python.backup.sqlite3.connect", side_effect=fake_connect):
        with pytest.raises(RuntimeError) as exc_info:
            backup_database(db, dest_dir, terminal_id="FAIL")
        assert "Integrity check failed" in str(exc_info.value)
        # Corrupted target file must have been deleted
        remaining = list(dest_dir.glob("pos-backup-FAIL-*.sqlite"))
        assert len(remaining) == 0


def test_backup_retention_keeps_14_and_does_not_delete_other_files(test_db, tmp_path):
    """Retention keeps exactly 14 latest backups and never deletes other files."""
    db, _ = test_db
    dest_dir = tmp_path / "retention_test"
    dest_dir.mkdir(parents=True, exist_ok=True)

    # Place other non-backup files in the directory
    non_backup_files = [
        dest_dir / "notes.txt",
        dest_dir / "session.db",
        dest_dir / "receipt.pdf",
        dest_dir / "pos-other-export.sqlite",  # starts with pos-other-, NOT pos-backup-
    ]
    for nbf in non_backup_files:
        nbf.write_text("important data", encoding="utf-8")

    # Create 18 backups with distinct mtimes
    base_time = datetime(2026, 11, 1, 10, 0, 0)
    created_backups = []
    for i in range(1, 19):
        backup_path = dest_dir / f"pos-backup-TERM-{i:02d}.sqlite"
        backup_path.write_bytes(b"SQLite format 3\x00")
        mtime = (base_time + timedelta(hours=i)).timestamp()
        os.utime(backup_path, (mtime, mtime))
        created_backups.append(backup_path)

    assert len(list(dest_dir.glob("pos-backup-*.sqlite"))) == 18

    # Now run prune_backups directly
    deleted = prune_backups(dest_dir, keep_count=14)
    assert len(deleted) == 4

    remaining_backups = list(dest_dir.glob("pos-backup-*.sqlite"))
    assert len(remaining_backups) == 14

    # Verify the 4 oldest were deleted
    for old_file in created_backups[:4]:
        assert not old_file.exists()

    # Verify the 14 newest still exist
    for new_file in created_backups[4:]:
        assert new_file.exists()

    # CRITICAL: Verify non-backup files were NEVER touched
    for nbf in non_backup_files:
        assert nbf.exists()
        assert nbf.read_text(encoding="utf-8") == "important data"


def test_backup_database_auto_prunes_on_call(test_db, tmp_path):
    """Calling backup_database automatically prunes older backups to 14."""
    db, _ = test_db
    dest_dir = tmp_path / "auto_prune_dir"
    dest_dir.mkdir(parents=True, exist_ok=True)

    # Pre-populate 14 dummy backup files
    for i in range(14):
        dummy = dest_dir / f"pos-backup-TERM-20261101-{i:06d}.sqlite"
        dummy.write_bytes(b"SQLite format 3\x00")
        mtime = 1000000 + i * 10
        os.utime(dummy, (mtime, mtime))

    assert len(list(dest_dir.glob("pos-backup-*.sqlite"))) == 14

    # Now call backup_database -> creates 15th, prunes to 14
    new_backup = backup_database(db, dest_dir, terminal_id="TERM")
    assert new_backup.exists()

    current_backups = list(dest_dir.glob("pos-backup-*.sqlite"))
    assert len(current_backups) == 14
    assert new_backup in current_backups


def test_daily_auto_backup_conditions(test_db, tmp_path):
    """Daily backup triggers when no backup exists or latest is older than 24h."""
    db, _ = test_db
    dest_dir = tmp_path / "daily_test"

    # 1. No backup exists -> creates one
    res = maybe_daily_auto_backup(db, dest_dir, terminal_id="DAILY")
    assert res is not None
    assert res.exists()

    # 2. Latest backup is fresh (< 24h) -> does not create new backup
    res2 = maybe_daily_auto_backup(db, dest_dir, terminal_id="DAILY")
    assert res2 is None
    assert len(list(dest_dir.glob("pos-backup-*.sqlite"))) == 1

    # 3. Modify latest backup mtime to 25 hours ago -> triggers backup
    old_time = datetime.now().timestamp() - (25 * 3600)
    os.utime(res, (old_time, old_time))

    res3 = maybe_daily_auto_backup(db, dest_dir, terminal_id="DAILY")
    assert res3 is not None
    assert res3.exists()
    assert len(list(dest_dir.glob("pos-backup-*.sqlite"))) == 2


def test_offline_pin_expiry_logic():
    """check_offline_pin_expiry warns when remaining time < 24 hours."""
    now_utc = datetime(2026, 11, 10, 12, 0, 0, tzinfo=timezone.utc)

    # 1. None or empty string -> no warning
    warn, msg = check_offline_pin_expiry(None, current_time=now_utc)
    assert not warn and msg == ""

    # 2. Remaining 48 hours -> no warning
    t_48h = (now_utc + timedelta(hours=48)).isoformat()
    warn, msg = check_offline_pin_expiry(t_48h, current_time=now_utc)
    assert not warn and msg == ""

    # 3. Remaining 24 hours + 1 minute -> no warning
    t_24h1m = (now_utc + timedelta(hours=24, minutes=1)).isoformat()
    warn, msg = check_offline_pin_expiry(t_24h1m, current_time=now_utc)
    assert not warn and msg == ""

    # 4. Remaining 23 hours 59 minutes -> WARN!
    t_23h59m = (now_utc + timedelta(hours=23, minutes=59)).isoformat()
    warn, msg = check_offline_pin_expiry(t_23h59m, current_time=now_utc)
    assert warn
    assert "PIN ออฟไลน์ของคุณจะหมดอายุ" in msg
    assert "เข้าระบบขณะออนไลน์เพื่อต่ออายุ" in msg

    # 5. Remaining 2 hours -> WARN!
    t_2h = (now_utc + timedelta(hours=2)).isoformat()
    warn, msg = check_offline_pin_expiry(t_2h, current_time=now_utc)
    assert warn
    assert "PIN ออฟไลน์ของคุณจะหมดอายุ" in msg
    assert "เข้าระบบขณะออนไลน์เพื่อต่ออายุ" in msg

    # 6. Already expired in the past -> WARN!
    t_past = (now_utc - timedelta(hours=1)).isoformat()
    warn, msg = check_offline_pin_expiry(t_past, current_time=now_utc)
    assert warn
    assert "PIN ออฟไลน์ของคุณจะหมดอายุ" in msg


def test_offline_pin_expiry_banner_in_ui(ui_scene):
    """PosWindow shows warning banner when cashier offline PIN expires in < 24h, hides when > 24h."""
    app, db, service, window, _ = ui_scene

    # Case 1: Cashier with offline_valid_until > 24h (e.g. 5 days ahead)
    window.refresh_pin_expiry_warning()
    app.processEvents()
    assert not window.pin_warning_bar.isVisible()

    # Case 2: Update cashier offline_valid_until to 12 hours ahead (< 24h)
    expiry_12h = (datetime.now(timezone.utc) + timedelta(hours=12)).isoformat()
    db.execute("UPDATE local_cashiers SET offline_valid_until = ? WHERE id = 1", (expiry_12h,))
    db.commit()
    window.cashier = db.execute("SELECT * FROM local_cashiers WHERE id = 1").fetchone()

    window.refresh_pin_expiry_warning()
    app.processEvents()
    assert window.pin_warning_bar.isVisible()
    assert "PIN ออฟไลน์ของคุณจะหมดอายุ" in window.pin_warning_label.text()
    assert "เข้าระบบขณะออนไลน์เพื่อต่ออายุ" in window.pin_warning_label.text()

    # Case 3: On lock_session, warning banner is hidden
    window.lock_session()
    app.processEvents()
    assert not window.pin_warning_bar.isVisible()

    # Case 4: Update cashier offline_valid_until to 3 days ahead (> 24h)
    expiry_3d = (datetime.now(timezone.utc) + timedelta(days=3)).isoformat()
    db.execute("UPDATE local_cashiers SET offline_valid_until = ? WHERE id = 1", (expiry_3d,))
    db.commit()
    window.cashier = db.execute("SELECT * FROM local_cashiers WHERE id = 1").fetchone()

    window.refresh_pin_expiry_warning()
    app.processEvents()
    assert not window.pin_warning_bar.isVisible()


def test_backup_failure_does_not_block_shift_close(ui_scene):
    """When pre-close backup fails, user is warned but shift closing is NOT blocked."""
    app, db, service, window, _ = ui_scene

    # Mock backup_database to raise an error
    with patch("pos_python.ui.backup_database", side_effect=RuntimeError("Disk full")):
        with patch.object(TouchMessage, "warning") as mock_warning:
            # Run pre-close backup
            window.backup_before_close()
            app.processEvents()

            # Warning is shown with clear reason
            assert mock_warning.called
            args, _ = mock_warning.call_args
            assert "สำรองข้อมูลก่อนปิดกะไม่สำเร็จ" in args[1]
            assert "Disk full" in args[2]
            assert "คุณยังสามารถทำรายการปิดกะต่อได้" in args[2]

    # Verify shift is still open and operable (closing shift was not aborted)
    assert window.shift_id is not None
    shift = db.execute("SELECT status FROM shifts WHERE id = ?", (window.shift_id,)).fetchone()
    assert shift["status"] == "open"


def test_backup_prompt_dialog_choices(ui_scene):
    """BackupCopyPromptDialog renders properly with touch buttons."""
    app, _, _, window, _ = ui_scene
    dialog = BackupCopyPromptDialog(window)
    dialog.show()
    app.processEvents()

    assert dialog.width() <= 900
    assert dialog.height() <= 680
    assert dialog.btn_save_copy.text() == "บันทึกอีกไฟล์"
    assert dialog.btn_skip.text() == "ข้าม ปิดกะต่อ"
    assert dialog.btn_save_copy.height() >= 48
    assert dialog.btn_skip.height() >= 48

    # Clicking skip rejects
    dialog.btn_skip.click()
    app.processEvents()
    assert not dialog.save_copy_chosen
    assert dialog.result() != QDialog.DialogCode.Accepted
    dialog.close()


def test_settings_dialog_backup_now(ui_scene):
    """SettingsDialog has 'สำรองข้อมูลตอนนี้' button and executes backup."""
    app, db, service, window, data_dir = ui_scene

    settings_dlg = window.dialog_types["settings"](window, window.last_sale_id)
    settings_dlg.show_section("สำรองฐานข้อมูล")
    app.processEvents()

    backup_btn = None
    for btn in settings_dlg.findChildren(QPushButton):
        if btn.text() == "สำรองข้อมูลตอนนี้":
            backup_btn = btn
            break

    assert backup_btn is not None, "Button 'สำรองข้อมูลตอนนี้' should exist in SettingsDialog"

    # Test clicking backup_database on settings dialog with skip copy
    import pos_python.touch_dialogs as td
    mock_prompt = Mock()
    mock_prompt.exec.return_value = QDialog.DialogCode.Rejected
    mock_prompt.save_copy_chosen = False

    with patch.object(td, "BackupCopyPromptDialog", return_value=mock_prompt):
        with patch.object(td.TouchMessage, "information") as mock_info:
            settings_dlg.backup_database()
            app.processEvents()
            assert mock_info.called

    # Verify a backup was created in data_dir / backups
    backups = list((data_dir / "backups").glob("pos-backup-*.sqlite"))
    assert len(backups) >= 1
    settings_dlg.close()
    settings_dlg.deleteLater()
