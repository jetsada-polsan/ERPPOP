"""One clock for POS business rules.

Do not call ``datetime.now()`` directly in authentication, shift, or sale
logic.  The terminal clock can be wrong; a successful ERP ping calibrates this
service with the server's UTC time and the offset is persisted in SQLite.
"""
from __future__ import annotations

import json
import sqlite3
from datetime import datetime, timedelta, timezone


class TimeService:
    SETTING_KEY = "server_time_offset_sec"

    def __init__(self, connection: sqlite3.Connection):
        self.db = connection

    def now(self) -> datetime:
        return datetime.now(timezone.utc) + timedelta(seconds=self.offset_seconds())

    def now_iso(self) -> str:
        return self.now().isoformat()

    def offset_seconds(self) -> int:
        row = self.db.execute("SELECT value FROM device_settings WHERE key = ?", (self.SETTING_KEY,)).fetchone()
        if not row:
            return 0
        try:
            return int(json.loads(row["value"]))
        except (TypeError, ValueError, json.JSONDecodeError):
            return 0

    def update_offset(self, server_time: str) -> int:
        """Persist the ERP-to-terminal clock offset from an ISO-8601 timestamp."""
        parsed = datetime.fromisoformat(str(server_time).replace("Z", "+00:00"))
        if parsed.tzinfo is None:
            raise ValueError("server_time ต้องมี timezone")
        offset = round((parsed.astimezone(timezone.utc) - datetime.now(timezone.utc)).total_seconds())
        self.db.execute(
            """INSERT INTO device_settings (key, value, updated_at) VALUES (?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at""",
            (self.SETTING_KEY, json.dumps(offset), datetime.now(timezone.utc).isoformat()),
        )
        return offset
