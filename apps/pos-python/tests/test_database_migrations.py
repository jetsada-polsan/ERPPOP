from __future__ import annotations

import sqlite3
import tempfile
import unittest
from pathlib import Path

from pos_python.database import connect


class DatabaseMigrationTest(unittest.TestCase):
    def test_existing_shift_table_gets_opening_note_without_resetting_rows(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "legacy-pos.sqlite"
            legacy = sqlite3.connect(path)
            legacy.execute(
                """CREATE TABLE shifts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    server_id INTEGER UNIQUE,
                    uuid TEXT NOT NULL UNIQUE,
                    branch_id INTEGER NOT NULL,
                    terminal_id TEXT NOT NULL,
                    cashier_id INTEGER NOT NULL,
                    opened_at TEXT NOT NULL,
                    closed_at TEXT,
                    opening_cash TEXT NOT NULL DEFAULT '0',
                    counted_cash TEXT,
                    cash_difference TEXT,
                    closing_note TEXT,
                    status TEXT NOT NULL
                )"""
            )
            legacy.execute(
                """INSERT INTO shifts
                   (id, uuid, branch_id, terminal_id, cashier_id, opened_at, opening_cash, status)
                   VALUES (1, 'old-shift', 1, 'POS-01', 7, '2026-11-01T00:00:00Z', '500.00', 'open')"""
            )
            legacy.commit()
            legacy.close()

            db = connect(path)
            try:
                columns = {row["name"] for row in db.execute("PRAGMA table_info(shifts)")}
                shift = db.execute("SELECT uuid, opening_cash, opening_note FROM shifts WHERE id = 1").fetchone()
                self.assertIn("opening_note", columns)
                self.assertEqual((shift["uuid"], shift["opening_cash"], shift["opening_note"]),
                                 ("old-shift", "500.00", None))
            finally:
                db.close()


if __name__ == "__main__":
    unittest.main()
