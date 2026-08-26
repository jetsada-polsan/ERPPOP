"""ค่าตั้งต่อเครื่อง POS — ที่อยู่ ERP กับ device token

อ่านจากไฟล์ pos-config.json ในโฟลเดอร์ข้อมูล (นอกโฟลเดอร์ติดตั้ง จะได้ไม่ถูกทับ
ตอนอัปเดตโปรแกรม) หรือจาก env สำหรับตอนทดสอบ ไม่มีค่าครบ = ยังไม่ผูกเครื่องกับ ERP
โปรแกรมจะรันโหมด demo/offline ต่อได้ ไม่ล้ม
"""
from __future__ import annotations

import json
import os
from dataclasses import dataclass
from pathlib import Path

CONFIG_FILENAME = "pos-config.json"


@dataclass(frozen=True)
class DeviceConfig:
    server_url: str
    device_token: str
    allow_insecure: bool = False


def load_device_config(data_dir: Path) -> DeviceConfig | None:
    """คืนค่าตั้งเครื่องถ้าผูกกับ ERP แล้ว ไม่งั้น None (โปรแกรมรัน offline/demo ต่อได้)"""
    server = os.environ.get("POS_SERVER_URL")
    token = os.environ.get("POS_DEVICE_TOKEN")
    insecure = os.environ.get("POS_ALLOW_INSECURE") == "1"

    path = Path(data_dir) / CONFIG_FILENAME
    if (not server or not token) and path.is_file():
        try:
            raw = json.loads(path.read_text(encoding="utf-8"))
        except (ValueError, OSError):
            raw = {}
        server = server or raw.get("server_url")
        token = token or raw.get("device_token")
        insecure = insecure or bool(raw.get("allow_insecure"))

    if not server or not token:
        return None
    return DeviceConfig(server_url=str(server).rstrip("/"), device_token=str(token), allow_insecure=bool(insecure))


def save_device_config(data_dir: Path, config: DeviceConfig) -> None:
    path = Path(data_dir) / CONFIG_FILENAME
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({
        "server_url": config.server_url,
        "device_token": config.device_token,
        "allow_insecure": config.allow_insecure,
    }, ensure_ascii=False, indent=2), encoding="utf-8")
