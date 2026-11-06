from __future__ import annotations

import json
import sqlite3
from datetime import datetime, timezone
from dataclasses import dataclass
from pathlib import Path
from uuid import uuid4

from .offline_auth import verify_offline_pin


@dataclass(frozen=True)
class QueuedCheckout:
    idempotency_key: str
    payload: dict[str, object]
    status: str
    attempts: int
    error: str | None = None
    receipt_no: str | None = None


@dataclass(frozen=True)
class LocalSale:
    idempotency_key: str
    receipt_no: str
    status: str
    total: float
    method: str
    created_at: str
    error: str | None = None


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
        self.connection.execute(
            """
            CREATE TABLE IF NOT EXISTS offline_cashiers (
                cashier_id INTEGER PRIMARY KEY,
                code TEXT NOT NULL,
                name TEXT NOT NULL,
                credential_json TEXT NOT NULL,
                synced_at TEXT NOT NULL
            )
            """
        )
        self.connection.execute(
            """
            CREATE TABLE IF NOT EXISTS sale_history (
                idempotency_key TEXT PRIMARY KEY,
                receipt_no TEXT NOT NULL,
                status TEXT NOT NULL CHECK(status IN ('pending', 'syncing', 'synced', 'failed', 'needs_review', 'void')),
                total REAL NOT NULL,
                method TEXT NOT NULL,
                payload_json TEXT NOT NULL,
                error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
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

    def save_sale(self, idempotency_key: str, payload: dict[str, object], total: float) -> None:
        now = datetime.now(timezone.utc).isoformat()
        with self.connection:
            self.connection.execute(
                """
                INSERT INTO sale_history (idempotency_key, receipt_no, status, total, method, payload_json, created_at, updated_at)
                VALUES (?, ?, 'pending', ?, ?, ?, ?, ?)
                ON CONFLICT(idempotency_key) DO NOTHING
                """,
                (idempotency_key, idempotency_key[:8].upper(), total, str(payload.get("method") or ""), json.dumps(payload, ensure_ascii=False), now, now),
            )

    def recent_sales(self, limit: int = 50) -> list[LocalSale]:
        rows = self.connection.execute(
            "SELECT idempotency_key, receipt_no, status, total, method, created_at, error FROM sale_history ORDER BY created_at DESC LIMIT ?",
            (max(1, min(limit, 500)),),
        ).fetchall()
        return [LocalSale(row["idempotency_key"], row["receipt_no"], row["status"], row["total"], row["method"], row["created_at"], row["error"]) for row in rows]

    def health(self) -> dict[str, object]:
        counts = {row["status"]: row["count"] for row in self.connection.execute("SELECT status, COUNT(*) AS count FROM checkout_queue GROUP BY status")}
        integrity = self.connection.execute("PRAGMA integrity_check").fetchone()[0]
        return {"integrity": integrity, "queued": counts.get("pending", 0) + counts.get("failed", 0), "review": counts.get("needs_review", 0), "synced": counts.get("synced", 0)}

    def backup_to(self, destination: Path) -> None:
        destination.parent.mkdir(parents=True, exist_ok=True)
        target = sqlite3.connect(destination)
        try:
            self.connection.backup(target)
        finally:
            target.close()

    def cache_offline_cashier(self, cashier: dict[str, object], credential: dict[str, object]) -> None:
        cashier_id = int(cashier.get("id") or 0)
        if cashier_id <= 0:
            return
        with self.connection:
            self.connection.execute(
                "INSERT OR REPLACE INTO offline_cashiers (cashier_id, code, name, credential_json, synced_at) VALUES (?, ?, ?, ?, ?)",
                (cashier_id, str(cashier.get("code") or ""), str(cashier.get("name") or ""), json.dumps(credential), datetime.now(timezone.utc).isoformat()),
            )

    def offline_cashiers_by_pin(self, pin: str) -> list[dict[str, object]]:
        rows = self.connection.execute("SELECT cashier_id, code, name, credential_json FROM offline_cashiers ORDER BY name, code").fetchall()
        matches = []
        for row in rows:
            credential = json.loads(row["credential_json"])
            if verify_offline_pin(pin, credential):
                matches.append({"id": row["cashier_id"], "code": row["code"], "name": row["name"], "offline_credential": credential})
        return matches

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
            self.connection.execute(
                "UPDATE sale_history SET status = 'synced', receipt_no = ?, error = NULL, updated_at = ? WHERE idempotency_key = ?",
                (receipt_no, datetime.now(timezone.utc).isoformat(), idempotency_key),
            )

    def mark_problem(self, idempotency_key: str, status: str, error: str) -> None:
        if status not in {"failed", "needs_review"}:
            raise ValueError("unsupported queue status")
        with self.connection:
            self.connection.execute(
                "UPDATE checkout_queue SET status = ?, error = ?, updated_at = CURRENT_TIMESTAMP WHERE idempotency_key = ?",
                (status, error, idempotency_key),
            )
            self.connection.execute(
                "UPDATE sale_history SET status = ?, error = ?, updated_at = ? WHERE idempotency_key = ?",
                (status, error, datetime.now(timezone.utc).isoformat(), idempotency_key),
            )
