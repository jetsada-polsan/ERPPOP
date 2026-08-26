"""ส่งบิลที่ค้างขึ้น ERP เป็นระยะและตอนเน็ตกลับมา — ทำงานบนเธรดแยก

เธรดนี้เปิด connection ของตัวเองไปที่ไฟล์ SQLite เดียวกัน (WAL รองรับหลาย
connection) เพราะ sqlite3 connection ใช้ข้ามเธรดไม่ได้ ตั้ง busy_timeout กัน
'database is locked' ตอน GUI กำลังเขียนบิลใหม่พอดี
"""
from __future__ import annotations

import threading
from pathlib import Path
from typing import Callable

from .database import connect
from .sync_service import SyncService


class SyncWorker:
    def __init__(self, db_path: Path, api, *, interval_seconds: float = 30.0,
                 on_result: Callable[[dict], None] | None = None):
        self.db_path = Path(db_path)
        self.api = api
        self.interval = interval_seconds
        self.on_result = on_result
        self._stop = threading.Event()
        self._wake = threading.Event()
        self._thread: threading.Thread | None = None

    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            return
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, name="pos-sync", daemon=True)
        self._thread.start()

    def wake(self) -> None:
        """เรียกหลังขายเสร็จ ให้ส่งทันทีไม่ต้องรอรอบถัดไป"""
        self._wake.set()

    def stop(self, timeout: float = 5.0) -> None:
        self._stop.set()
        self._wake.set()
        if self._thread:
            self._thread.join(timeout=timeout)

    def run_once(self) -> dict:
        """ดึงคิวหนึ่งรอบด้วย connection ชั่วคราว — ใช้ในเทสต์และตอน wake ก็ได้"""
        db = connect(self.db_path)
        try:
            db.execute("PRAGMA busy_timeout = 5000")
            return SyncService(db, self.api).sync_pending_sales()
        finally:
            db.close()

    def _run(self) -> None:
        while not self._stop.is_set():
            try:
                result = self.run_once()
                if self.on_result:
                    self.on_result(result)
            except Exception:
                # เธรดพื้นหลังต้องไม่ล้มทั้งตัวเพราะ sync พลาดรอบเดียว รอบหน้าลองใหม่
                pass
            # ตื่นเมื่อครบ interval หรือถูกปลุกหลังขาย
            self._wake.wait(timeout=self.interval)
            self._wake.clear()
