from __future__ import annotations

import json
import sqlite3
from dataclasses import dataclass
from pathlib import Path
from uuid import uuid4


@dataclass(frozen=True)
class QueuedCheckout:
    idempotency_key: str
    payload: dict[str, object]
    status: str
    attempts: int
    error: str | None = None
    receipt_no: str | None = None


class LocalStore:
    def __init__(self, database_path: Path) -> None:
        self.connection = sqlite3.connect(database_path)
        self.connection.row_factory = sqlite3.Row
        self.connection.execute("PRAGMA journal_mode=WAL")
        self.connection.execute(
            """
            CREATE TABLE IF NOT EXISTS checkout_queue (
                idempotency_key TEXT PRIMARY KEY,
                payload_json TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending', 'syncing', 'synced', 'failed', 'needs_review')),
                attempts INTEGER NOT NULL DEFAULT 0,
                error TEXT,
                receipt_no TEXT,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            """
        )
        self.connection.commit()

    def close(self) -> None:
        self.connection.close()

    def queue_checkout(self, payload: dict[str, object], idempotency_key: str | None = None) -> str:
        key = idempotency_key or str(uuid4())
        payload_copy = {**payload, "idempotency_key": key}
        with self.connection:
            self.connection.execute(
                """
                INSERT INTO checkout_queue (idempotency_key, payload_json, status)
                VALUES (?, ?, 'pending')
                ON CONFLICT(idempotency_key) DO NOTHING
                """,
                (key, json.dumps(payload_copy, ensure_ascii=False, separators=(",", ":"))),
            )
        return key

    def pending_checkouts(self) -> list[QueuedCheckout]:
        rows = self.connection.execute(
            """
            SELECT idempotency_key, payload_json, status, attempts, error, receipt_no
            FROM checkout_queue
            WHERE status IN ('pending', 'failed')
            ORDER BY created_at, idempotency_key
            """
        ).fetchall()
        return [
            QueuedCheckout(row["idempotency_key"], json.loads(row["payload_json"]), row["status"], row["attempts"], row["error"], row["receipt_no"])
            for row in rows
        ]

    def checkout(self, idempotency_key: str) -> QueuedCheckout | None:
        row = self.connection.execute(
            "SELECT idempotency_key, payload_json, status, attempts, error, receipt_no FROM checkout_queue WHERE idempotency_key = ?",
            (idempotency_key,),
        ).fetchone()
        if row is None:
            return None
        return QueuedCheckout(row["idempotency_key"], json.loads(row["payload_json"]), row["status"], row["attempts"], row["error"], row["receipt_no"])

    def mark_syncing(self, idempotency_key: str) -> None:
        with self.connection:
            self.connection.execute(
                "UPDATE checkout_queue SET status = 'syncing', attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP WHERE idempotency_key = ?",
                (idempotency_key,),
            )

    def mark_synced(self, idempotency_key: str, receipt_no: str) -> None:
        with self.connection:
            self.connection.execute(
                "UPDATE checkout_queue SET status = 'synced', receipt_no = ?, error = NULL, updated_at = CURRENT_TIMESTAMP WHERE idempotency_key = ?",
                (receipt_no, idempotency_key),
            )

    def mark_problem(self, idempotency_key: str, status: str, error: str) -> None:
        if status not in {"failed", "needs_review"}:
            raise ValueError("unsupported queue status")
        with self.connection:
            self.connection.execute(
                "UPDATE checkout_queue SET status = ?, error = ?, updated_at = CURRENT_TIMESTAMP WHERE idempotency_key = ?",
                (status, error, idempotency_key),
            )
