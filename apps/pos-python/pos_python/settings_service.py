from __future__ import annotations

import json
import sqlite3
from dataclasses import asdict, dataclass

from .services import now


@dataclass(frozen=True)
class PrinterProfile:
    name: str
    driver_type: str
    connection_type: str
    address: str | None
    paper_width_mm: int
    open_drawer: bool = True


@dataclass(frozen=True)
class ReceiptTemplate:
    name: str
    paper_width_mm: int
    header_text: str
    footer_text: str
    show_tax_id: bool = True
    show_cashier: bool = True
    show_barcode: bool = False


class SettingsService:
    """Local-only configuration. ERP sync must never overwrite a terminal printer setting."""

    def __init__(self, connection: sqlite3.Connection):
        self.db = connection

    def set_device_setting(self, key: str, value: object) -> None:
        self.db.execute(
            """INSERT INTO device_settings (key, value, updated_at) VALUES (?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at""",
            (key, json.dumps(value, ensure_ascii=False), now()),
        )
        self.db.commit()

    def get_device_setting(self, key: str, default: object = None) -> object:
        row = self.db.execute("SELECT value FROM device_settings WHERE key = ?", (key,)).fetchone()
        return json.loads(row["value"]) if row else default

    def save_printer_profile(self, profile: PrinterProfile) -> int:
        if profile.paper_width_mm not in (58, 80):
            raise ValueError("รองรับกระดาษ 58 หรือ 80 มม. เท่านั้น")
        timestamp = now()
        with self.db:
            self.db.execute(
                """INSERT INTO printer_profiles (name, driver_type, connection_type, address, paper_width_mm, open_drawer, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(name) DO UPDATE SET driver_type=excluded.driver_type, connection_type=excluded.connection_type,
                  address=excluded.address, paper_width_mm=excluded.paper_width_mm, open_drawer=excluded.open_drawer, updated_at=excluded.updated_at""",
                (profile.name, profile.driver_type, profile.connection_type, profile.address, profile.paper_width_mm,
                 int(profile.open_drawer), timestamp, timestamp),
            )
            row = self.db.execute("SELECT id FROM printer_profiles WHERE name = ?", (profile.name,)).fetchone()
            self.set_device_setting("active_printer_profile", profile.name)
        return int(row["id"])

    def save_receipt_template(self, template: ReceiptTemplate) -> int:
        if template.paper_width_mm not in (58, 80):
            raise ValueError("รองรับกระดาษ 58 หรือ 80 มม. เท่านั้น")
        timestamp = now()
        with self.db:
            current = self.db.execute("SELECT COALESCE(MAX(revision), 0) AS revision FROM receipt_templates WHERE name = ?", (template.name,)).fetchone()
            revision = int(current["revision"]) + 1
            self.db.execute("UPDATE receipt_templates SET active = 0 WHERE name = ?", (template.name,))
            cursor = self.db.execute(
                """INSERT INTO receipt_templates (name, revision, paper_width_mm, header_text, footer_text, show_tax_id, show_cashier, show_barcode, active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)""",
                (template.name, revision, template.paper_width_mm, template.header_text, template.footer_text,
                 int(template.show_tax_id), int(template.show_cashier), int(template.show_barcode), timestamp, timestamp),
            )
            self.set_device_setting("active_receipt_template", {"name": template.name, "revision": revision})
        return int(cursor.lastrowid)

    def active_receipt_template(self) -> sqlite3.Row | None:
        selected = self.get_device_setting("active_receipt_template")
        if not selected:
            return None
        return self.db.execute("SELECT * FROM receipt_templates WHERE name = ? AND revision = ? AND active = 1", (selected["name"], selected["revision"])).fetchone()

    def export_settings(self) -> dict:
        return {
            "device_settings": {row["key"]: json.loads(row["value"]) for row in self.db.execute("SELECT key, value FROM device_settings")},
            "printer_profiles": [dict(row) for row in self.db.execute("SELECT * FROM printer_profiles WHERE active = 1")],
            "receipt_template": dict(self.active_receipt_template()) if self.active_receipt_template() else None,
        }
