"""ส่งบิลที่ค้างขึ้น ERP เป็นระยะ ปรับจังหวะเองตามงานค้าง — ตอบสนองเร็วเมื่อเน็ตกลับ

ไม่มี event ของ OS บอกว่าเน็ตกลับมา จึงใช้วิธี poll แต่ให้ฉลาดขึ้น: ถ้ายังมีบิล
ค้างในคิว (ออฟไลน์อยู่หรือส่งไม่สำเร็จ) จะวนถี่ (retry_interval) พอเน็ตกลับรอบ
ถัดไปเคลียร์ทันที; ถ้าคิวว่างก็วนห่าง (idle_interval) ประหยัดทั้งเครื่องและ ERP

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
    def __init__(self, db_path: Path, api, *, idle_interval: float = 30.0,
                 retry_interval: float = 5.0, on_result: Callable[[dict], None] | None = None,
                 refresh_down: Callable[[], dict] | None = None):
        self.db_path = Path(db_path)
        self.api = api
        self.idle_interval = idle_interval
        self.retry_interval = retry_interval
        self.on_result = on_result
        # bootstrap supplies a callback that opens its own SQLite connection.
        # The worker must never share the GUI connection across threads.
        self.refresh_down = refresh_down
        self.needs_down_sync = False
        # สถานะนี้จะเป็น True ได้ต่อเมื่อ bootstrap/manual health check เรียก
        # /api/pos/ping ผ่านแล้วเท่านั้น ห้ามอนุมานจาก pending == 0 เพราะคิวว่าง
        # ไม่ได้แปลว่า Device Token ถูกยืนยันแล้ว
        self.online = False
        self.last_error = "ยังไม่ได้ยืนยัน Device Token กับ ERP"
        self.pending = 0
        self.last_result: dict = {"synced": 0, "failed": 0}
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
        """ดึงคิวหนึ่งรอบด้วย connection ชั่วคราว — ใช้ในเทสต์และตอน wake ก็ได้

        อัปเดต self.online/pending/last_result ให้ GUI อ่านได้ทันที
        """
        db = connect(self.db_path)
        try:
            db.execute("PRAGMA busy_timeout = 5000")
            download = {}
            if self.needs_down_sync and self.refresh_down is not None:
                download = self.refresh_down()
                self.needs_down_sync = False
                self.online = True
                self.last_error = ""
            service = SyncService(db, self.api)
            result = service.sync_pending_sales()
            if download:
                result = {**result, "download": download}
            self.pending = self._pending_count(db)
            self.last_result = result
            # ผล sync คิวอย่างเดียวไม่ใช่ health check: คิวว่างไม่เรียก API และ
            # จึงไม่มีสิทธิ์เปลี่ยนสถานะ Device Token ให้เป็นออนไลน์
            if result["failed"]:
                self.online = False
                self.last_error = f"มีรายการ sync ล้มเหลว {result['failed']} รายการ"
            return result
        except Exception as error:
            self.online = False
            self.last_error = str(error)
            # A failed request is also the reconnect trigger. The next cycle
            # will refresh server master data before attempting the outbox.
            self.needs_down_sync = True
            raise
        finally:
            db.close()

    def next_interval(self) -> float:
        """ค้างอยู่ก็ถี่ ว่างก็ห่าง — คืนช่วงรอสำหรับรอบถัดไป"""
        return self.retry_interval if self.pending > 0 else self.idle_interval

    def _pending_count(self, db) -> int:
        return int(db.execute(
            "SELECT (SELECT count(*) FROM sync_outbox WHERE status IN ('pending', 'failed')) + "
            "(SELECT count(*) FROM auth_events_outbox WHERE synced = 0)"
        ).fetchone()[0])

    def _run(self) -> None:
        while not self._stop.is_set():
            try:
                result = self.run_once()
                if self.on_result:
                    self.on_result(result)
            except Exception:
                # เธรดพื้นหลังต้องไม่ล้มทั้งตัวเพราะ sync พลาดรอบเดียว รอบหน้าลองใหม่
                pass
            # ตื่นเมื่อครบช่วง (ปรับตามงานค้าง) หรือถูกปลุกหลังขาย
            self._wake.wait(timeout=self.next_interval())
            self._wake.clear()
