"""POS SQLite database backup and offline PIN expiry warning utilities.

Safe online backups using sqlite3.Connection.backup(), retention management,
and offline credential expiry detection.
"""
from __future__ import annotations

import json
import re
import sqlite3
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Union

from .time_service import local_datetime_text


def _resolve_terminal_id(conn: sqlite3.Connection, default: str = "POS") -> str:
    """Extract configured terminal identifier from connection."""
    try:
        row = conn.execute("SELECT value FROM device_settings WHERE key = 'terminal_code'").fetchone()
        if row and row[0]:
            val = row[0]
            try:
                val = json.loads(val)
            except Exception:
                pass
            if val and str(val).strip():
                return str(val).strip()
    except Exception:
        pass

    try:
        row = conn.execute("SELECT terminal_id FROM shifts ORDER BY id DESC LIMIT 1").fetchone()
        if row and row[0] and str(row[0]).strip():
            return str(row[0]).strip()
    except Exception:
        pass

    return default


def sanitize_filename_token(token: str) -> str:
    """Sanitize string for safe use in filenames across OSes."""
    cleaned = re.sub(r"[^a-zA-Z0-9_\-]", "_", str(token or "").strip())
    return cleaned or "POS"


def prune_backups(dest_dir: Union[Path, str], keep_count: int = 14) -> list[Path]:
    """Prune backups in dest_dir keeping only the newest keep_count files.

    Strict safety: ONLY removes files in dest_dir whose filename starts with 'pos-backup-'.
    Any other files (e.g. database, receipts, notes) are never touched.
    """
    dest_path = Path(dest_dir)
    if not dest_path.is_dir():
        return []

    # Only primary backup files (ignore temporary -wal / -shm companions during counting)
    backup_files = [
        f for f in dest_path.iterdir()
        if f.is_file() and f.name.startswith("pos-backup-") and not f.name.endswith(("-wal", "-shm"))
    ]

    # Sort newest first using nanosecond mtime and filename
    backup_files.sort(key=lambda p: (p.stat().st_mtime_ns, p.name), reverse=True)

    deleted: list[Path] = []
    if len(backup_files) > keep_count:
        for f in backup_files[keep_count:]:
            try:
                f.unlink(missing_ok=True)
                Path(str(f) + "-wal").unlink(missing_ok=True)
                Path(str(f) + "-shm").unlink(missing_ok=True)
                deleted.append(f)
            except OSError:
                pass
    return deleted


def backup_database(
    db_source: Union[Path, str, sqlite3.Connection],
    dest_dir: Union[Path, str],
    terminal_id: str | None = None,
    prune: bool = True,
) -> Path:
    """Create an online SQLite backup safely using sqlite3.Connection.backup().

    Never copies the database file directly. Verifies PRAGMA integrity_check == 'ok'
    before completing, prunes destination directory to retain at most 14 latest backups,
    and returns the Path of the created backup file.
    """
    dest_path = Path(dest_dir)
    dest_path.mkdir(parents=True, exist_ok=True)

    # Determine source connection
    owns_source_conn = False
    if isinstance(db_source, sqlite3.Connection):
        src_conn = db_source
    else:
        src_path = Path(db_source)
        if not src_path.is_file():
            raise FileNotFoundError(f"ไม่พบไฟล์ฐานข้อมูลต้นฉบับ: {src_path}")
        src_conn = sqlite3.connect(str(src_path))
        owns_source_conn = True

    try:
        # Determine terminal id for filename
        resolved_terminal = terminal_id or _resolve_terminal_id(src_conn, default="POS")
        safe_terminal = sanitize_filename_token(resolved_terminal)

        # Build filename: pos-backup-<terminal>-<YYYYMMDD-HHMMSS>.sqlite
        timestamp_str = datetime.now().strftime("%Y%m%d-%H%M%S")
        base_name = f"pos-backup-{safe_terminal}-{timestamp_str}"
        target_file = dest_path / f"{base_name}.sqlite"

        # Avoid collision if called within the same second
        counter = 1
        while target_file.exists():
            target_file = dest_path / f"{base_name}_{counter}.sqlite"
            counter += 1

        # Safe online backup via conn.backup()
        dest_conn = sqlite3.connect(str(target_file))
        try:
            src_conn.backup(dest_conn)
        finally:
            dest_conn.close()

        # Verify integrity of target file
        chk_conn = sqlite3.connect(str(target_file))
        try:
            cursor = chk_conn.execute("PRAGMA integrity_check")
            rows = cursor.fetchall()
            if rows != [("ok",)]:
                reasons = ", ".join(str(r[0]) for r in rows)
                raise RuntimeError(f"Integrity check failed: {reasons}")
        finally:
            chk_conn.close()

        # ลบไฟล์เก่าเฉพาะโฟลเดอร์สำรองอัตโนมัติของเครื่อง โฟลเดอร์ที่ผู้ใช้เลือกเอง (USB)
        # อาจใช้ร่วมกันหลายเครื่อง ห้ามลบไฟล์สำรองของเครื่องอื่นหรือของวันก่อน
        if prune:
            prune_backups(dest_path, keep_count=14)

        return target_file

    except Exception:
        # On failure, remove corrupted or incomplete target file
        if "target_file" in locals() and target_file.exists():
            try:
                target_file.unlink(missing_ok=True)
            except OSError:
                pass
        raise
    finally:
        if owns_source_conn:
            src_conn.close()


def maybe_daily_auto_backup(
    db_source: Union[Path, str, sqlite3.Connection],
    dest_dir: Union[Path, str],
    terminal_id: str | None = None,
) -> Path | None:
    """Silently run daily backup on app launch if latest backup in dest_dir is older than 24h."""
    try:
        dest_path = Path(dest_dir)
        dest_path.mkdir(parents=True, exist_ok=True)

        backup_files = [
            f for f in dest_path.iterdir()
            if f.is_file() and f.name.startswith("pos-backup-") and not f.name.endswith(("-wal", "-shm"))
        ]

        needs_backup = False
        if not backup_files:
            needs_backup = True
        else:
            latest_mtime = max(f.stat().st_mtime for f in backup_files)
            now_ts = datetime.now().timestamp()
            if (now_ts - latest_mtime) >= 24 * 3600:
                needs_backup = True

        if needs_backup:
            return backup_database(db_source, dest_path, terminal_id=terminal_id)
    except Exception:
        # Daily startup backup must remain silent
        pass
    return None


def check_offline_pin_expiry(
    offline_valid_until: str | None,
    current_time: datetime | None = None,
) -> tuple[bool, str]:
    """Check if cashier offline PIN is expiring within 24 hours.

    Returns:
        (should_warn, warning_message)
    """
    if not offline_valid_until:
        return False, ""

    try:
        dt = datetime.fromisoformat(str(offline_valid_until).replace("Z", "+00:00"))
    except ValueError:
        return False, ""

    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)

    if current_time is None:
        current_time = datetime.now(timezone.utc)
    elif current_time.tzinfo is None:
        current_time = current_time.replace(tzinfo=timezone.utc)

    remaining = dt - current_time
    if remaining < timedelta(hours=24):
        time_text = local_datetime_text(offline_valid_until, fmt="%d/%m/%Y %H:%M")
        msg = f"PIN ออฟไลน์ของคุณจะหมดอายุ {time_text} เข้าระบบขณะออนไลน์เพื่อต่ออายุ"
        return True, msg

    return False, ""
