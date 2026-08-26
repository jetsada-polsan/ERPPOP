"""ต่อเครื่อง POS เข้ากับ ERP ตอนเปิดโปรแกรม

รวมทุกชิ้นเข้าด้วยกัน: อ่าน config → สร้าง client → ping → ดึงข้อมูลลงเครื่อง →
เริ่มเธรดส่งบิลค้าง คืน OnlineContext ให้ UI ใช้ ถ้ายังไม่ผูกเครื่อง (ไม่มี config)
คืน None แล้ว main จะ seed demo ให้แทน โปรแกรมยังเปิดขายออฟไลน์ได้

ping/sync ที่ล้มเพราะเน็ตไม่ถือว่า bootstrap ล้ม — คืน context พร้อม error ไว้ให้ UI
โชว์ แล้วยังทำงานด้วยข้อมูลที่แคชไว้รอบก่อนได้ (offline-first)
"""
from __future__ import annotations

import sqlite3
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

from .api_client import LaravelPosClient
from .config import DeviceConfig, load_device_config
from .provisioning import ProvisioningService
from .sync_worker import SyncWorker


@dataclass
class OnlineContext:
    api: LaravelPosClient
    provisioning: ProvisioningService
    worker: SyncWorker
    branch_id: int | None = None
    terminal_id: str | None = None
    online: bool = False
    error: str = ""
    profile: dict[str, Any] = field(default_factory=dict)


def bootstrap(data_dir: Path, db: sqlite3.Connection, db_path: Path,
              config: DeviceConfig | None = None) -> OnlineContext | None:
    """คืน OnlineContext ถ้าผูกเครื่องกับ ERP แล้ว, None ถ้ายังไม่ผูก (ให้ seed demo)"""
    config = config or load_device_config(data_dir)
    if config is None:
        return None

    api = LaravelPosClient(config.server_url, config.device_token, allow_insecure=config.allow_insecure)
    prov = ProvisioningService(db, api)
    worker = SyncWorker(db_path, api)
    ctx = OnlineContext(api=api, provisioning=prov, worker=worker)

    try:
        profile = prov.ping()
        ctx.online = True
        ctx.profile = profile
        ctx.branch_id = profile.get("branch_id")
        ctx.terminal_id = (profile.get("device") or {}).get("terminal_code")
        if ctx.branch_id:
            prov.sync_down(int(ctx.branch_id))
    except Exception as error:
        # เน็ตล่ม/ERP ตอบช้า — ยังเปิดขายด้วยข้อมูลที่แคชไว้ได้ ไม่ใช่ bootstrap ล้ม
        ctx.online = False
        ctx.error = str(error)
        cached = _cached_setting(db, "branch_id")
        ctx.branch_id = int(cached) if cached else None
        ctx.terminal_id = _cached_setting(db, "terminal_code")

    worker.start()
    return ctx


def _cached_setting(db: sqlite3.Connection, key: str) -> Any:
    row = db.execute("SELECT value FROM device_settings WHERE key = ?", (key,)).fetchone()
    if not row:
        return None
    import json
    try:
        return json.loads(row["value"])
    except (ValueError, TypeError):
        return row["value"]
