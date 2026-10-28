"""สถานะเครื่องสำหรับหน้า ตั้งค่า > สถานะเครื่อง

รวมทุกอย่างที่ IT หน้าร้านต้องดูไว้ที่เดียว แยกจาก Qt เพื่อทดสอบได้โดยไม่เปิดหน้าต่าง:
เครื่องนี้คือใคร (สาขา/รหัสเครื่อง/ERP), เชื่อม ERP อยู่ไหม, แต่ละชุดข้อมูล sync ล่าสุดเมื่อไร,
และมีบิล/ประวัติค้างส่งหรือถูก ERP ปฏิเสธกี่รายการ พร้อมสิ่งที่ควรทำต่อเป็นภาษาคน
"""
from __future__ import annotations

import json
import sqlite3
from dataclasses import dataclass, field
from typing import Any

# ชุดข้อมูลที่ sync ตามลำดับที่หน้าร้านเข้าใจ
DATASETS = [
    ("device_profile", "โปรไฟล์เครื่อง"),
    ("catalog", "สินค้าและราคา"),
    ("cashiers", "คนขาย / PIN"),
    ("sales_outbox", "ส่งบิลขาย"),
    ("auth_events", "ประวัติการเข้าใช้"),
]


@dataclass
class DatasetStatus:
    key: str
    label: str
    state: str  # ok | failed | running | never
    detail: str


@dataclass
class DeviceStatus:
    paired: bool
    online: bool
    server_url: str
    branch: str
    terminal: str
    pending_sales: int
    review_sales: int
    pending_auth: int
    datasets: list[DatasetStatus] = field(default_factory=list)
    error: str = ""

    @property
    def headline(self) -> str:
        if not self.paired:
            return "ยังไม่ผูกกับ ERP — ไปที่ ผูกเครื่องกับ ERP แล้ววาง Token"
        if not self.online:
            return "ออฟไลน์ — ขายต่อได้ บิลจะส่งขึ้น ERP เองเมื่อเน็ตกลับมา"
        if self.review_sales:
            return f"ERP ไม่รับบิล {self.review_sales} ใบ — แก้ที่ ERP แล้วกด ส่งบิลค้างใหม่"
        if any(d.state == "failed" for d in self.datasets):
            return "เชื่อม ERP ได้ แต่มีข้อมูลบางชุด sync ไม่สำเร็จ — กด Sync ตอนนี้"
        if self.pending_sales:
            return f"เชื่อม ERP แล้ว กำลังส่งบิล {self.pending_sales} ใบ"
        return "เชื่อม ERP แล้ว ข้อมูลเป็นปัจจุบัน"

    @property
    def level(self) -> str:
        """สีของแถบสถานะ: ok เขียว / warn เหลือง / bad แดง"""
        if not self.paired or self.review_sales:
            return "bad"
        if not self.online or any(d.state == "failed" for d in self.datasets):
            return "warn"
        return "ok"


def _setting(db: sqlite3.Connection, key: str) -> Any:
    row = db.execute("SELECT value FROM device_settings WHERE key = ?", (key,)).fetchone()
    if not row:
        return None
    try:
        return json.loads(row["value"])
    except (TypeError, ValueError):
        return row["value"]


def _count(db: sqlite3.Connection, sql: str) -> int:
    try:
        return int(db.execute(sql).fetchone()[0])
    except sqlite3.Error:
        return 0


def collect(db: sqlite3.Connection, *, server_url: str = "", online: bool = False, error: str = "",
            paired: bool | None = None) -> DeviceStatus:
    branch_name = _setting(db, "branch_name")
    branch = str(branch_name or "-")

    states = {str(r["entity"]): dict(r) for r in db.execute("SELECT * FROM sync_state").fetchall()}
    datasets = []
    for key, label in DATASETS:
        row = states.get(key)
        if not row or row["status"] == "never":
            datasets.append(DatasetStatus(key, label, "never", "ยังไม่เคย sync"))
        elif row["status"] == "synced":
            datasets.append(DatasetStatus(key, label, "ok", f"{row['item_count']} รายการ · {row['last_success_at'] or '-'}"))
        elif row["status"] == "failed":
            datasets.append(DatasetStatus(key, label, "failed", row["last_error"] or "ผิดพลาด ดูบันทึก"))
        else:
            datasets.append(DatasetStatus(key, label, "running", "กำลัง sync"))

    return DeviceStatus(
        paired=bool(server_url) if paired is None else paired,
        online=online,
        server_url=server_url or "-",
        branch=branch,
        terminal=str(_setting(db, "terminal_code") or "-"),
        # ใช้คิวเดียวกับ SyncService: pending = รอส่ง, needs_review = ERP ปฏิเสธต้องแก้ก่อน
        pending_sales=_count(db, "SELECT count(*) FROM sync_outbox WHERE status = 'pending'"),
        review_sales=_count(db, "SELECT count(*) FROM sync_outbox WHERE aggregate_type = 'sale' AND status = 'needs_review'"),
        pending_auth=_count(db, "SELECT count(*) FROM auth_events_outbox WHERE synced = 0"),
        datasets=datasets,
        error=error,
    )
